<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\App;
use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Integrations\OpenSIPS\OpensipsProvisioner;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\CustomerRepository;
use Ownswitch\Services\Billing\RateResolver;
use Ownswitch\Services\Billing\RatingService;
use Ownswitch\Services\Routing\OpensipsRoutingSync;
use Ownswitch\Services\Routing\RouteSimulator;
use Ownswitch\Services\Settings\SettingsService;

/** Routing rules (kind=rules), routing plans (kind=plans) and the route simulator. */
final class RoutingController extends CrudController
{
    public function __construct(private string $kind = 'rules')
    {
        $plans = $kind === 'plans';
        $this->base = $plans ? '/routing/plans' : '/routing';
        $this->singular = $plans ? 'Trunk group' : 'Route';
        $this->plural = $plans ? 'Trunk Groups' : 'Routes';
        $this->emptyHint = $plans
            ? 'No trunk groups yet (Magnus Trunk Groups). Choose Order/LCR, add Routes (prefix → trunk), assign the group to Customers.'
            : 'No routes. Map a dial prefix to a Provider Trunk inside a Trunk Group. Same prefix + different priority = failover.';
    }

    protected function repo(): BaseRepository
    {
        return $this->kind === 'plans'
            ? new BaseRepository($this->db(), 'routing_plans', ['code', 'name', 'strategy', 'status', 'description', 'is_demo'], 'none', ['code', 'name'], ['id', 'code', 'name'])
            : new BaseRepository($this->db(), 'routing_rules', ['routing_plan_id', 'prefix', 'carrier_trunk_id', 'priority', 'status'], 'none', ['prefix'], ['id', 'prefix', 'priority', 'routing_plan_id']);
    }

    protected function filterColumns(): array
    {
        return $this->kind === 'plans' ? ['status'] : ['routing_plan_id', 'status'];
    }

    protected function fields(Request $r): array
    {
        $status = ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['active' => 'Active', 'disabled' => 'Disabled'], 'list' => true];
        if ($this->kind === 'plans') {
            return [
                ['name' => 'name', 'label' => 'Trunk group name', 'required' => true, 'max' => 120, 'list' => true],
                ['name' => 'strategy', 'label' => 'Type', 'type' => 'select', 'required' => true, 'options' => [
                    'priority' => 'Order / failover',
                    'lcr' => 'Lowest cost (LCR)',
                ], 'list' => true, 'help' => 'Magnus trunk-group order types'],
                $status,
                ['name' => 'description', 'label' => 'Description', 'max' => 255],
            ];
        }
        $plans = [];
        foreach ($this->db()->query('SELECT id, name FROM routing_plans ORDER BY name LIMIT 500')->fetchAll() as $p) {
            $plans[$p['id']] = $p['name'];
        }
        $trunks = [];
        foreach ($this->db()->query('SELECT t.id, t.name, c.name AS carrier FROM carrier_trunks t JOIN carriers c ON c.id = t.carrier_id ORDER BY c.name, t.name LIMIT 500')->fetchAll() as $t) {
            $trunks[$t['id']] = "{$t['carrier']} / {$t['name']}";
        }
        return [
            ['name' => 'routing_plan_id', 'label' => 'Trunk group', 'type' => 'select', 'required' => true, 'options' => $plans, 'list' => true],
            ['name' => 'prefix', 'label' => 'Prefix', 'type' => 'prefix', 'required' => true, 'list' => true, 'help' => 'Dial prefix for this route, e.g. 1 or 44'],
            ['name' => 'carrier_trunk_id', 'label' => 'Trunk', 'type' => 'select', 'required' => true, 'options' => $trunks, 'list' => true],
            ['name' => 'priority', 'label' => 'Order', 'type' => 'int', 'min' => 0, 'max' => 100000, 'required' => true, 'default' => '100', 'list' => true, 'help' => 'Lower = tried first (Order strategy)'],
            $status,
        ];
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        if ($this->kind === 'plans') {
            $data = $this->autoCode($data, $existing, 'routing_plans');
            $data['is_demo'] = $existing['is_demo'] ?? 0;
        }
        return $data;
    }

    protected function afterSave(Request $r, int $id, array $data, ?array $existing): ?string
    {
        if ($this->kind === 'rules') {
            $this->pushRoutes();
        }
        return null;
    }

    protected function afterDelete(Request $r, array $row): void
    {
        if ($this->kind === 'rules') {
            $this->pushRoutes();
        }
    }

    private function pushRoutes(): void
    {
        $prov = OpensipsProvisioner::fromConfig();
        if (!$prov->enabled()) {
            return;
        }
        try {
            (new OpensipsRoutingSync($this->db(), $prov))->syncAll();
        } catch (\Throwable $e) {
            App::session()->flash('warning', 'Saved, but OpenSIPS route sync failed: ' . (App::config('app.debug') ? $e->getMessage() : 'run php bin/sync-opensips-trunks.php'));
        }
    }

    /** GET /routing/simulate */
    public function simulateForm(Request $r, array $p = []): Response
    {
        return $this->view('routing/simulate', [
            'title' => 'Route Simulator', 'customers' => $this->customerOptions($r), 'result' => null,
            'values' => ['customer_id' => '', 'dst' => '', 'seconds' => '60'], 'error' => null,
        ]);
    }

    /** POST /routing/simulate */
    public function simulate(Request $r, array $p = []): Response
    {
        $scope = $this->scope($r);
        $cid = (string) $r->input('customer_id', '');
        $dst = (string) $r->input('dst', '');
        $secs = (string) $r->input('seconds', '60');
        $values = ['customer_id' => $cid, 'dst' => $dst, 'seconds' => $secs];
        $error = null;
        $result = null;
        if (!ctype_digit($cid) || !preg_match('/^\d{1,3}$/', $secs) || preg_match('/\d/', $dst) !== 1 || strlen($dst) > 40) {
            $error = 'Choose a customer, enter a destination number and a sample duration (0-999s).';
        } elseif ((new CustomerRepository($this->db()))->find((int) $cid, $scope) === null) {
            $error = 'Customer not found.';
        } else {
            $sim = new RouteSimulator($this->db(), new RateResolver($this->db(), new SettingsService($this->db())), new RatingService());
            $result = $sim->simulate($scope, (int) $cid, $dst, (int) $secs);
        }
        return $this->view('routing/simulate', [
            'title' => 'Route Simulator', 'customers' => $this->customerOptions($r), 'result' => $result, 'values' => $values, 'error' => $error,
        ], $error ? 422 : 200);
    }
}

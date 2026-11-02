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

/**
 * Trunk Groups (kind=plans) at /trunk-groups — multi-trunk + Priority/LCR/Random.
 * Routes (kind=rules) at /routing — optional prefix→trunk overrides.
 */
final class RoutingController extends CrudController
{
    /** @var list<string> */
    private array $pendingTrunkIds = [];

    public function __construct(private string $kind = 'rules')
    {
        $plans = $kind === 'plans';
        $this->base = $plans ? '/trunk-groups' : '/routing';
        $this->singular = $plans ? 'Trunk group' : 'Route';
        $this->plural = $plans ? 'Trunk Groups' : 'Routes';
        $this->emptyHint = $plans
            ? 'No trunk groups. Pick Type (Priority/failover, LCR, Random), add Trunks, then select the group on a Tariff.'
            : 'Optional prefix overrides. Prefer adding trunks on the Trunk Group for Magnus-style failover.';
    }

    protected function repo(): BaseRepository
    {
        return $this->kind === 'plans'
            ? new BaseRepository($this->db(), 'routing_plans', ['code', 'name', 'strategy', 'status', 'description', 'is_demo'], 'none', ['code', 'name'], ['id', 'code', 'name', 'strategy', 'status'])
            : new BaseRepository($this->db(), 'routing_rules', ['routing_plan_id', 'prefix', 'carrier_trunk_id', 'priority', 'status'], 'none', ['prefix'], ['id', 'prefix', 'priority', 'routing_plan_id']);
    }

    protected function filterColumns(): array
    {
        return $this->kind === 'plans' ? ['status'] : ['routing_plan_id', 'status'];
    }

    protected function fields(Request $r): array
    {
        $status = ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['active' => 'Active', 'disabled' => 'Inactive'], 'list' => true, 'default' => 'active'];
        if ($this->kind === 'plans') {
            $trunks = [];
            foreach ($this->db()->query('SELECT t.id, t.name, c.name AS carrier FROM carrier_trunks t JOIN carriers c ON c.id = t.carrier_id WHERE t.status = \'active\' ORDER BY c.name, t.name LIMIT 500')->fetchAll() as $t) {
                $trunks[(string) $t['id']] = "{$t['carrier']} / {$t['name']}";
            }
            return [
                ['name' => 'name', 'label' => 'Name', 'required' => true, 'max' => 120, 'list' => true],
                ['name' => 'strategy', 'label' => 'Type', 'type' => 'select', 'required' => true, 'options' => [
                    'priority' => 'Priority / failover',
                    'lcr' => 'LCR',
                    'random' => 'Random',
                ], 'list' => true, 'default' => 'priority', 'help' => 'How trunks in this group are tried on a call'],
                [
                    'name' => 'trunk_ids',
                    'label' => 'Trunks',
                    'type' => 'multiselect',
                    'wide' => true,
                    'required' => true,
                    'add_label' => '+ Add trunk…',
                    'options' => $trunks,
                    'help' => 'Select one or more trunks. Used when a Tariff points at this Trunk Group.',
                ],
                $status,
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
            ['name' => 'prefix', 'label' => 'Prefix', 'type' => 'prefix', 'required' => true, 'list' => true],
            ['name' => 'carrier_trunk_id', 'label' => 'Trunk', 'type' => 'select', 'required' => true, 'options' => $trunks, 'list' => true],
            ['name' => 'priority', 'label' => 'Order', 'type' => 'int', 'min' => 0, 'max' => 100000, 'required' => true, 'default' => '100', 'list' => true],
            $status,
        ];
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        if ($this->kind === 'plans') {
            $data = $this->autoCode($data, $existing, 'routing_plans');
            $data['is_demo'] = $existing['is_demo'] ?? 0;
            $raw = $data['trunk_ids'] ?? '';
            unset($data['trunk_ids']);
            $ids = [];
            foreach (preg_split('/[\s,;|]+/', (string) $raw) ?: [] as $p) {
                $p = trim($p);
                if ($p !== '' && ctype_digit($p)) {
                    $ids[] = $p;
                }
            }
            $this->pendingTrunkIds = array_values(array_unique($ids));
            if ($this->pendingTrunkIds === []) {
                throw new \DomainException('Select at least one Trunk for this Trunk Group.');
            }
            $strategy = (string) ($data['strategy'] ?? 'priority');
            if (!in_array($strategy, ['priority', 'lcr', 'random'], true)) {
                throw new \DomainException('Type must be Priority / failover, LCR or Random.');
            }
        }
        return $data;
    }

    protected function afterSave(Request $r, int $id, array $data, ?array $existing): ?string
    {
        if ($this->kind === 'plans') {
            $this->syncGroupTrunks($id, $this->pendingTrunkIds);
            $this->pushRoutes();
            return 'Trunk Group saved with ' . count($this->pendingTrunkIds) . ' trunk(s). Select it on a Tariff.';
        }
        if ($this->kind === 'rules') {
            $this->pushRoutes();
        }
        return null;
    }

    protected function afterDelete(Request $r, array $row): void
    {
        if ($this->kind === 'plans') {
            $this->db()->prepare('DELETE FROM routing_rules WHERE routing_plan_id = ?')->execute([(int) $row['id']]);
            $this->pushRoutes();
        }
        if ($this->kind === 'rules') {
            $this->pushRoutes();
        }
    }

    protected function formValues(Request $r, ?array $existing, array $values): array
    {
        if ($this->kind === 'plans' && $existing !== null && !isset($values['trunk_ids'])) {
            $st = $this->db()->prepare("SELECT carrier_trunk_id FROM routing_rules WHERE routing_plan_id = ? AND prefix = '*' AND status = 'active' ORDER BY priority ASC, id ASC");
            $st->execute([(int) $existing['id']]);
            $values['trunk_ids'] = implode(',', array_map('strval', $st->fetchAll(\PDO::FETCH_COLUMN)));
        }
        return $values;
    }

    /** @param list<string> $trunkIds */
    private function syncGroupTrunks(int $planId, array $trunkIds): void
    {
        $pdo = $this->db();
        $pdo->prepare("DELETE FROM routing_rules WHERE routing_plan_id = ? AND prefix = '*'")->execute([$planId]);
        $ins = $pdo->prepare('INSERT INTO routing_rules (routing_plan_id, prefix, carrier_trunk_id, priority, status) VALUES (?,?,?,?,?)');
        $prio = 10;
        foreach ($trunkIds as $tid) {
            $ins->execute([$planId, '*', (int) $tid, $prio, 'active']);
            $prio += 10;
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

    public function simulateForm(Request $r, array $p = []): Response
    {
        return $this->view('routing/simulate', [
            'title' => 'Route Simulator', 'customers' => $this->customerOptions($r), 'result' => null,
            'values' => ['customer_id' => '', 'dst' => '', 'seconds' => '60'], 'error' => null,
        ]);
    }

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

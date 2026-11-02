<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\CustomerRepository;
use Ownswitch\Repositories\ResellerRepository;
use Ownswitch\Support\TenantScope;

/** Magnus “Users” (clients) — Ownswitch customers table. */
final class CustomerController extends CrudController
{
    protected string $base = '/users';
    protected string $singular = 'User';
    protected string $plural = 'Users';
    protected array $writeRoles = ['super_admin', 'admin', 'reseller'];
    protected string $emptyHint = 'No users yet. Magnus flow: create a User, assign Plan + Trunk Group, add SIP Users, then Refill credit.';

    protected function repo(): BaseRepository
    {
        return new CustomerRepository($this->db());
    }

    protected function filterColumns(): array
    {
        return ['status', 'billing_mode'];
    }

    protected function fields(Request $r): array
    {
        $staff = $this->scope($r)->isStaff();
        $f = [
            ['name' => 'name', 'label' => 'Name', 'required' => true, 'max' => 120, 'list' => true],
            ['name' => 'contact_email', 'label' => 'Email', 'type' => 'email', 'max' => 190, 'list' => true],
        ];
        if ($staff) {
            $res = ['' => '— none —'];
            foreach ((new ResellerRepository($this->db()))->all(TenantScope::global(), [], 500, 'name') as $x) {
                $res[$x['id']] = $x['name'];
            }
            $f[] = ['name' => 'reseller_id', 'label' => 'Reseller', 'type' => 'select', 'options' => $res, 'list' => true];
        }
        array_push($f,
            ['name' => 'billing_mode', 'label' => 'Type paid', 'type' => 'select', 'required' => true, 'options' => ['prepaid' => 'Prepaid', 'postpaid' => 'Postpaid'], 'list' => true],
            ['name' => 'currency', 'label' => 'Currency', 'required' => true, 'max' => 3, 'pattern' => '/^[A-Z]{3}$/', 'default' => 'USD', 'list' => true],
            ['name' => 'timezone', 'label' => 'Timezone', 'required' => true, 'max' => 64, 'default' => 'UTC'],
            ['name' => 'status', 'label' => 'Active', 'type' => 'select', 'required' => true, 'options' => ['active' => 'Active', 'suspended' => 'Suspended', 'closed' => 'Closed'], 'list' => true],
            ['name' => 'credit_limit', 'label' => 'Credit limit', 'type' => 'number', 'default' => '0'],
            ['name' => 'max_concurrent_calls', 'label' => 'Call limit', 'type' => 'int', 'min' => 1, 'max' => 100000, 'required' => true, 'default' => '10', 'list' => true],
        );
        if ($staff) {
            $plans = ['' => '— select plan —'];
            foreach ($this->db()->query('SELECT id, name FROM rate_plans ORDER BY name LIMIT 500')->fetchAll() as $p) {
                $plans[$p['id']] = $p['name'];
            }
            $routes = ['' => '— select trunk group —'];
            foreach ($this->db()->query('SELECT id, name FROM routing_plans ORDER BY name LIMIT 500')->fetchAll() as $p) {
                $routes[$p['id']] = $p['name'];
            }
            array_push($f,
                ['name' => 'rate_plan_id', 'label' => 'Plan', 'type' => 'select', 'options' => $plans, 'list' => true, 'help' => 'Magnus plan — Tariffs under this plan set sell price by Destination'],
                ['name' => 'routing_plan_id', 'label' => 'Trunk group', 'type' => 'select', 'options' => $routes, 'help' => 'Default trunk group when a Tariff has none set'],
            );
        }
        array_push($f,
            ['name' => 'media_policy', 'label' => 'Media', 'type' => 'select', 'options' => ['' => 'Default (from Settings)', 'direct' => 'Direct', 'relay' => 'Relay (RTPengine)', 'record' => 'Record'], 'help' => 'Default follows Settings → Configuration → Default media policy.'],
            ['name' => 'recording_enabled', 'label' => 'Record call', 'type' => 'checkbox'],
        );
        return $f;
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        $scope = $this->scope($r);
        if ($scope->role === 'reseller') {
            $data['reseller_id'] = $scope->resellerId;
        } elseif (!$scope->isStaff()) {
            throw new \DomainException('Not permitted.');
        }
        if (isset($data['reseller_id']) && $data['reseller_id'] !== null && $scope->isStaff()) {
            $x = (new ResellerRepository($this->db()))->find((int) $data['reseller_id'], $scope);
            if ($x === null) {
                throw new \DomainException('Reseller not found.');
            }
        }
        $data = $this->autoCode($data, $existing, 'customers');
        $data['is_demo'] = $existing['is_demo'] ?? 0;
        return $data;
    }

    protected function afterSave(Request $r, int $id, array $data, ?array $existing): ?string
    {
        $this->db()->prepare('INSERT IGNORE INTO balances (customer_id, balance, reserved) VALUES (?,0,0)')->execute([$id]);
        return null;
    }
}

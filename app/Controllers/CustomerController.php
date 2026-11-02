<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\CustomerRepository;
use Ownswitch\Repositories\ResellerRepository;
use Ownswitch\Support\TenantScope;

/** Magnus Clients → Users — core fields only. */
final class CustomerController extends CrudController
{
    protected string $base = '/users';
    protected string $singular = 'User';
    protected string $plural = 'Users';
    protected array $writeRoles = ['super_admin', 'admin', 'reseller'];
    protected string $emptyHint = 'No users yet. Create User → assign Plan → add SIP User → Refill credit.';

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
            ['name' => 'name', 'label' => 'Username', 'required' => true, 'max' => 120, 'list' => true, 'help' => 'Display / account name'],
            ['name' => 'contact_email', 'label' => 'Email', 'type' => 'email', 'max' => 190, 'list' => true],
        ];
        if ($staff) {
            $res = ['' => '— none —'];
            foreach ((new ResellerRepository($this->db()))->all(TenantScope::global(), [], 500, 'name') as $x) {
                $res[$x['id']] = $x['name'];
            }
            $f[] = ['name' => 'reseller_id', 'label' => 'Group (reseller)', 'type' => 'select', 'options' => $res, 'list' => true];
            $plans = ['' => '— select —'];
            foreach ($this->db()->query('SELECT id, name FROM rate_plans ORDER BY name LIMIT 500')->fetchAll() as $p) {
                $plans[$p['id']] = $p['name'];
            }
            $routes = ['' => '— select —'];
            foreach ($this->db()->query('SELECT id, name FROM routing_plans ORDER BY name LIMIT 500')->fetchAll() as $p) {
                $routes[$p['id']] = $p['name'];
            }
            array_push($f,
                ['name' => 'rate_plan_id', 'label' => 'Plan', 'type' => 'select', 'options' => $plans, 'list' => true],
                ['name' => 'routing_plan_id', 'label' => 'Trunk group', 'type' => 'select', 'options' => $routes, 'list' => true],
            );
        }
        array_push($f,
            ['name' => 'billing_mode', 'label' => 'Type paid', 'type' => 'select', 'required' => true, 'options' => ['prepaid' => 'Prepaid', 'postpaid' => 'Postpaid'], 'list' => true, 'default' => 'prepaid'],
            ['name' => 'status', 'label' => 'Active', 'type' => 'select', 'required' => true, 'options' => ['active' => 'Active', 'suspended' => 'Inactive', 'closed' => 'Closed'], 'list' => true, 'default' => 'active'],
            ['name' => 'credit_limit', 'label' => 'Credit limit', 'type' => 'number', 'default' => '0', 'help' => 'Postpaid'],
            ['name' => 'max_concurrent_calls', 'label' => 'Call limit', 'type' => 'int', 'min' => 1, 'max' => 100000, 'required' => true, 'default' => '10', 'list' => true],
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
        if ($existing === null) {
            $data['currency'] = $data['currency'] ?? 'USD';
            $data['timezone'] = $data['timezone'] ?? 'UTC';
            $data['media_policy'] = null;
            $data['recording_enabled'] = $data['recording_enabled'] ?? 0;
        }
        $data['is_demo'] = $existing['is_demo'] ?? 0;
        return $data;
    }

    protected function afterSave(Request $r, int $id, array $data, ?array $existing): ?string
    {
        $this->db()->prepare('INSERT IGNORE INTO balances (customer_id, balance, reserved) VALUES (?,0,0)')->execute([$id]);
        return null;
    }
}

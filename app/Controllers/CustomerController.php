<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\CustomerRepository;
use Ownswitch\Repositories\ResellerRepository;
use Ownswitch\Support\TenantScope;

final class CustomerController extends CrudController
{
    protected string $base = '/customers';
    protected string $singular = 'Customer';
    protected string $plural = 'Customers';
    protected array $writeRoles = ['super_admin', 'admin', 'reseller'];
    protected string $emptyHint = 'No customers yet. Create the first customer, then add SIP users or IP authentication and assign a rate plan.';

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
            ['name' => 'code', 'label' => 'Code', 'required' => true, 'max' => 32, 'pattern' => '/^[A-Za-z0-9_-]{2,32}$/', 'readonly_on_edit' => true, 'list' => true, 'help' => 'Letters, digits, _ and - only.'],
            ['name' => 'contact_email', 'label' => 'Contact email', 'type' => 'email', 'max' => 190],
        ];
        if ($staff) {
            $res = ['' => '- direct (no reseller) -'];
            foreach ((new ResellerRepository($this->db()))->all(TenantScope::global(), [], 500, 'name') as $x) {
                $res[$x['id']] = $x['name'];
            }
            $f[] = ['name' => 'reseller_id', 'label' => 'Reseller', 'type' => 'select', 'options' => $res, 'list' => true];
        }
        array_push($f,
            ['name' => 'billing_mode', 'label' => 'Billing mode', 'type' => 'select', 'required' => true, 'options' => ['prepaid' => 'Prepaid', 'postpaid' => 'Postpaid'], 'list' => true],
            ['name' => 'currency', 'label' => 'Currency', 'required' => true, 'max' => 3, 'pattern' => '/^[A-Z]{3}$/', 'default' => 'USD'],
            ['name' => 'timezone', 'label' => 'Display timezone', 'required' => true, 'max' => 64, 'default' => 'UTC'],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['active' => 'Active', 'suspended' => 'Suspended', 'closed' => 'Closed'], 'list' => true],
            ['name' => 'credit_limit', 'label' => 'Credit limit (postpaid)', 'type' => 'number', 'default' => '0'],
            ['name' => 'max_concurrent_calls', 'label' => 'Max concurrent calls', 'type' => 'int', 'min' => 1, 'max' => 100000, 'required' => true, 'default' => '10'],
        );
        if ($staff) {
            $plans = ['' => '- none -'];
            foreach ($this->db()->query('SELECT id, code, name FROM rate_plans ORDER BY name LIMIT 500')->fetchAll() as $p) {
                $plans[$p['id']] = "{$p['name']} ({$p['code']})";
            }
            $routes = ['' => '- none -'];
            foreach ($this->db()->query('SELECT id, code, name FROM routing_plans ORDER BY name LIMIT 500')->fetchAll() as $p) {
                $routes[$p['id']] = "{$p['name']} ({$p['code']})";
            }
            array_push($f,
                ['name' => 'rate_plan_id', 'label' => 'Rate plan', 'type' => 'select', 'options' => $plans],
                ['name' => 'routing_plan_id', 'label' => 'Routing plan', 'type' => 'select', 'options' => $routes],
            );
        }
        array_push($f,
            ['name' => 'media_policy', 'label' => 'Media policy', 'type' => 'select', 'options' => ['' => '- global default -', 'direct' => 'Direct', 'relay' => 'Relay (RTPengine)', 'record' => 'Record']],
            ['name' => 'recording_enabled', 'label' => 'Recording allowed', 'type' => 'checkbox'],
        );
        return $f;
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        $scope = $this->scope($r);
        if ($scope->role === 'reseller') {
            $data['reseller_id'] = $scope->resellerId;   // resellers can only create/keep their own customers
        } elseif (!$scope->isStaff()) {
            throw new \DomainException('Not permitted.');
        }
        if (isset($data['reseller_id']) && $data['reseller_id'] !== null && $scope->isStaff()) {
            $x = (new ResellerRepository($this->db()))->find((int) $data['reseller_id'], $scope);
            if ($x === null) {
                throw new \DomainException('Reseller not found.');
            }
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

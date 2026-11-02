<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\App;
use Ownswitch\Http\Request;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\CustomerRepository;
use Ownswitch\Repositories\ResellerRepository;
use Ownswitch\Repositories\UserRepository;
use Ownswitch\Support\TenantScope;

/** Magnus Clients → Users — core fields + portal login (email/password). */
final class CustomerController extends CrudController
{
    protected string $base = '/users';
    protected string $singular = 'User';
    protected string $plural = 'Users';
    protected array $writeRoles = ['super_admin', 'admin', 'reseller'];
    protected string $emptyHint = 'No users yet. Create User → set Email + Portal password → assign Plan → add SIP User → Refill credit.';

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
            ['name' => 'contact_email', 'label' => 'Email (portal login)', 'type' => 'email', 'required' => true, 'max' => 190, 'list' => true, 'help' => 'Used to sign in at /login and see CDR, balance, reports'],
            [
                'name' => 'portal_password',
                'label' => 'Portal password',
                'type' => 'password',
                'max' => 128,
                'help' => 'Required on create. On edit, leave blank to keep current. Min 12 chars with upper, lower, digit. User can also change it under their profile menu.',
            ],
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

        $pw = isset($data['portal_password']) ? (string) $data['portal_password'] : '';
        unset($data['portal_password']);
        $r->setAttr('_portal_password', $pw);

        $email = trim((string) ($data['contact_email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException('Email is required for portal login.');
        }
        if ($existing === null && $pw === '') {
            throw new \DomainException('Portal password is required when creating a user.');
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

        $row = $this->repo()->findInternal($id);
        if ($row === null) {
            return null;
        }
        $pw = $r->attr('_portal_password');
        $plain = is_string($pw) && $pw !== '' ? $pw : null;
        $users = new UserRepository($this->db());
        $res = $users->upsertCustomerPortal(
            $id,
            (string) ($row['contact_email'] ?? ''),
            (string) ($row['name'] ?? ''),
            $plain,
            isset($row['reseller_id']) && $row['reseller_id'] !== null ? (int) $row['reseller_id'] : null,
            (string) ($row['status'] ?? 'active'),
        );
        if (!$res['ok']) {
            App::session()->flash('warning', 'User saved, but portal login failed: ' . $res['detail']);
            return null;
        }
        return $res['detail'] . ' They sign in with email + portal password to view CDR, balance and reports.';
    }
}

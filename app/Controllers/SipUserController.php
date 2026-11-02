<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\App;
use Ownswitch\Http\Request;
use Ownswitch\Integrations\OpenSIPS\OpensipsProvisioner;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\SipAccountRepository;

final class SipUserController extends CrudController
{
    protected string $base = '/sip-users';
    protected string $singular = 'SIP User';
    protected string $plural = 'SIP Users';
    protected array $writeRoles = ['super_admin', 'admin', 'reseller', 'customer_owner', 'customer_tech'];
    protected string $emptyHint = 'No SIP users. Create an account (like MagnusBilling User) so a phone or PBX can register.';

    private ?string $plainPassword = null;

    protected function repo(): BaseRepository
    {
        return new SipAccountRepository($this->db());
    }

    protected function filterColumns(): array
    {
        return ['customer_id', 'status'];
    }

    protected function fields(Request $r): array
    {
        $f = [];
        if (!$this->scope($r)->isCustomerRole()) {
            $f[] = ['name' => 'customer_id', 'label' => 'Customer', 'type' => 'select', 'required' => true, 'options' => $this->customerOptions($r), 'readonly_on_edit' => true, 'list' => true];
        }
        array_push($f,
            ['name' => 'username', 'label' => 'SIP username', 'required' => true, 'max' => 64, 'pattern' => '/^[A-Za-z0-9._-]{3,64}$/', 'readonly_on_edit' => true, 'list' => true, 'help' => 'Account used to register — e.g. 1001 or office1'],
            ['name' => 'password', 'label' => 'SIP password', 'type' => 'password', 'max' => 128, 'help' => 'Leave blank to auto-generate a strong password (create) or keep current (edit). Min 12 if typed.'],
            ['name' => 'caller_id', 'label' => 'Caller ID', 'max' => 32, 'pattern' => '/^\+?[0-9]{3,30}$/', 'list' => true],
            ['name' => 'max_channels', 'label' => 'Call limit', 'type' => 'int', 'min' => 1, 'max' => 10000, 'required' => true, 'default' => '2', 'list' => true],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['active' => 'Active', 'disabled' => 'Disabled'], 'list' => true],
            ['name' => 'notes', 'label' => 'Notes', 'max' => 255],
        );
        return $f;
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        $domain = (string) App::config('app.integrations.opensips.sip_domain', 'sip.example.test');
        $this->plainPassword = null;
        $pw = $data['password'] ?? null;
        unset($data['password']);
        if ($pw !== null && $pw !== '' && mb_strlen((string) $pw) < 12) {
            throw new \DomainException('SIP password must be at least 12 characters.');
        }
        if ($existing === null) {
            $data['domain'] = $domain;
            $pw = ($pw === null || $pw === '') ? self::generatePassword() : $pw;
            $username = (string) $data['username'];
        } else {
            $username = (string) $existing['username'];
            $domain = (string) $existing['domain'];
        }
        if ($pw !== null && $pw !== '') {
            [$ha1, $ha1b] = OpensipsProvisioner::ha1($username, $domain, (string) $pw);
            $data['ha1'] = $ha1;
            $data['ha1b'] = $ha1b;
            $data['password_changed_at'] = gmdate('Y-m-d H:i:s');
            $this->plainPassword = (string) $pw;
        }
        return $data;
    }

    protected function afterSave(Request $r, int $id, array $data, ?array $existing): ?string
    {
        $row = $this->repo()->findInternal($id);
        $prov = OpensipsProvisioner::fromConfig();
        if ($row && $prov->enabled()) {
            try {
                $row['status'] === 'active'
                    ? $prov->upsertSubscriber((string) $row['username'], (string) $row['domain'], (string) $row['ha1'], (string) $row['ha1b'])
                    : $prov->removeSubscriber((string) $row['username'], (string) $row['domain']);
            } catch (\Throwable $e) {
                App::session()->flash('warning', 'Saved, but OpenSIPS provisioning failed: ' . (App::config('app.debug') ? $e->getMessage() : 'see server log'));
            }
        }
        if ($this->plainPassword !== null && $row) {
            return "SIP user saved. Username: {$row['username']}  Domain: {$row['domain']}  Password (shown once): {$this->plainPassword}";
        }
        return null;
    }

    protected function afterDelete(Request $r, array $row): void
    {
        $prov = OpensipsProvisioner::fromConfig();
        if ($prov->enabled()) {
            try {
                $prov->removeSubscriber((string) $row['username'], (string) $row['domain']);
            } catch (\Throwable) {
                App::session()->flash('warning', 'Deleted locally, but OpenSIPS deprovisioning failed.');
            }
        }
    }

    public static function generatePassword(int $bytes = 15): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', 'Ab'), '=') . '7';
    }
}

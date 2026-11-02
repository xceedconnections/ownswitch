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
            $f[] = ['name' => 'customer_id', 'label' => 'User', 'type' => 'select', 'required' => true, 'options' => $this->customerOptions($r), 'readonly_on_edit' => true, 'list' => true];
        }
        array_push($f,
            ['name' => 'username', 'label' => 'SIP user', 'required' => true, 'max' => 64, 'pattern' => '/^[A-Za-z0-9._-]{3,64}$/', 'readonly_on_edit' => true, 'list' => true],
            ['name' => 'password', 'label' => 'SIP password', 'type' => 'password', 'max' => 128, 'help' => 'Blank = auto-generate (create) or keep current (edit). Min 12 if typed.'],
            ['name' => 'caller_id', 'label' => 'CallerID', 'max' => 32, 'pattern' => '/^\+?[0-9]{3,30}$/', 'list' => true],
            ['name' => 'max_channels', 'label' => 'Call limit', 'type' => 'int', 'min' => 1, 'max' => 10000, 'required' => true, 'default' => '2', 'list' => true],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['active' => 'Active', 'disabled' => 'Inactive'], 'list' => true, 'default' => 'active'],
            ['name' => 'notes', 'label' => 'Description', 'max' => 255],
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
            // Password change: re-bind HA1 to the configured SIP domain/realm (must match OpenSIPS www_authorize).
            if ($pw !== null && $pw !== '') {
                $data['domain'] = $domain;
            } else {
                $domain = (string) $existing['domain'];
            }
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
        if ($row) {
            if (!$prov->enabled()) {
                App::session()->flash('warning', 'Saved in portal, but OpenSIPS provisioning is not available: '
                    . ($prov->initError() !== '' ? $prov->initError() : 'OPENSIPS_ENABLED off')
                    . '. Run: sudo bash /opt/ownswitch-src/deploy/fix-opensips-provision.sh');
            } else {
                try {
                    $row['status'] === 'active'
                        ? $prov->upsertSubscriber((string) $row['username'], (string) $row['domain'], (string) $row['ha1'], (string) $row['ha1b'])
                        : $prov->removeSubscriber((string) $row['username'], (string) $row['domain']);
                } catch (\Throwable $e) {
                    error_log('[ownswitch] OpenSIPS provision SIP user: ' . $e->getMessage());
                    $hint = preg_replace('/\s+/', ' ', $e->getMessage()) ?? $e->getMessage();
                    App::session()->flash('warning', 'Saved, but OpenSIPS provisioning failed: ' . mb_substr($hint, 0, 240));
                }
            }
        }
        if ($this->plainPassword !== null && $row) {
            $host = (string) App::config('app.integrations.opensips.sip_domain', (string) $row['domain']);
            return "SIP user saved. Softphone: User={$row['username']}  Pass={$this->plainPassword}  "
                . "Domain/Realm={$row['domain']}  Server/Proxy={$host}  Port=5060  Transport=UDP";
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

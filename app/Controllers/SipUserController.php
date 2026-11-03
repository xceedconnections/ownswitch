<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\App;
use Ownswitch\Http\Request;
use Ownswitch\Integrations\OpenSIPS\OpensipsProvisioner;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\SipAccountRepository;
use Ownswitch\Services\Sip\SipOptionsProbe;
use Ownswitch\Support\MediaPolicy;

/**
 * SIP Users — only OpenSIPS-relevant fields (auth, host ACL, CallerID, media, call limit).
 */
final class SipUserController extends CrudController
{
    protected string $base = '/sip-users';
    protected string $singular = 'SIP User';
    protected string $plural = 'SIP Users';
    protected array $writeRoles = ['super_admin', 'admin', 'reseller', 'customer_owner', 'customer_tech'];
    protected string $emptyHint = 'No SIP users. Create an account so a phone or PBX can register to OpenSIPS.';

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
        $routes = ['' => '— use Tariff trunk group —'];
        try {
            foreach ($this->db()->query('SELECT id, name FROM routing_plans ORDER BY name LIMIT 500')->fetchAll() as $p) {
                $routes[$p['id']] = $p['name'];
            }
        } catch (\Throwable) {
        }
        array_push($f,
            ['name' => 'username', 'label' => 'SIP user', 'required' => true, 'max' => 64, 'pattern' => '/^[A-Za-z0-9._-]{3,64}$/', 'readonly_on_edit' => true, 'list' => true],
            ['name' => 'password', 'label' => 'SIP password', 'type' => 'password', 'max' => 128, 'help' => 'Blank = auto-generate (create) or keep current (edit). Min 12 if typed.'],
            ['name' => 'caller_id', 'label' => 'CallerID', 'max' => 32, 'pattern' => '/^\+?[0-9]{3,30}$/', 'list' => true, 'help' => 'Default CallerID. Overridden if the selected Trunk has From user set.'],
            ['name' => 'sip_host', 'label' => 'Host', 'max' => 190, 'default' => 'dynamic', 'list' => true, 'help' => 'dynamic = register from any IP; fixed IP enables IP auth in OpenSIPS address table'],
            [
                'name' => 'routing_plan_id',
                'label' => 'Trunk group',
                'type' => 'select',
                'options' => $routes,
                'list' => true,
                'help' => 'Overrides the Tariff trunk group for this SIP user when set.',
            ],
            [
                'name' => 'media_policy',
                'label' => 'Directmedia',
                'type' => 'select',
                'options' => [
                    '' => 'Default (Settings)',
                    'direct' => 'Direct (no RTPengine)',
                    'relay' => 'Relay (RTPengine)',
                ],
                'default' => '',
                'list' => true,
                'help' => 'Same as Default media policy: Default follows Settings; Direct skips media relay; Relay uses RTPengine',
            ],
            ['name' => 'max_channels', 'label' => 'Call limit', 'type' => 'int', 'min' => -1, 'max' => 10000, 'required' => true, 'default' => '2', 'list' => true, 'help' => '-1 = unlimited'],
            ['name' => 'reg_status', 'label' => 'Registration', 'list' => true, 'list_only' => true],
            ['name' => 'latency_ms', 'label' => 'Latency', 'list' => true, 'list_only' => true],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['active' => 'Active', 'disabled' => 'Inactive'], 'list' => true, 'default' => 'active'],
            ['name' => 'notes', 'label' => 'Description', 'max' => 255],
        );
        return $f;
    }

    protected function enrichRows(Request $r, array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }
        $prov = OpensipsProvisioner::fromConfig();
        $regs = $prov->enabled() ? $prov->registeredContacts() : [];
        foreach ($rows as &$row) {
            $user = strtolower((string) ($row['username'] ?? ''));
            $reg = $regs[$user] ?? null;
            if ($reg === null) {
                $row['reg_status'] = 'offline';
                $row['latency_ms'] = '—';
                continue;
            }
            $row['reg_status'] = 'registered';
            $uri = trim((string) ($reg['received'] ?? '')) !== ''
                ? (string) $reg['received']
                : (string) ($reg['contact'] ?? '');
            $ep = SipOptionsProbe::endpointFromUri($uri);
            $ms = $ep ? SipOptionsProbe::rttMs($ep['host'], $ep['port']) : null;
            $row['latency_ms'] = $ms ?? 'n/a';
        }
        unset($row);
        return $rows;
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
        if (array_key_exists('media_policy', $data)) {
            $data['media_policy'] = MediaPolicy::normalizeStored(
                isset($data['media_policy']) ? (string) $data['media_policy'] : null
            );
            // SIP users: only default/direct/relay (no record)
            if ($data['media_policy'] === 'record') {
                $data['media_policy'] = 'relay';
            }
        }
        if (isset($data['sip_host'])) {
            $host = trim((string) $data['sip_host']);
            $data['sip_host'] = $host === '' ? 'dynamic' : $host;
        }
        $tg = $data['routing_plan_id'] ?? null;
        $data['routing_plan_id'] = ($tg === null || $tg === '') ? null : (int) $tg;
        return $data;
    }

    protected function afterSave(Request $r, int $id, array $data, ?array $existing): ?string
    {
        $row = $this->repo()->findInternal($id);
        $prov = OpensipsProvisioner::fromConfig();
        if ($row) {
            if (!$prov->enabled()) {
                App::session()->flash('warning', 'Saved in portal, but OpenSIPS provisioning is not available: '
                    . ($prov->initError() !== '' ? $prov->initError() : 'OPENSIPS_ENABLED off'));
            } else {
                try {
                    if ($row['status'] === 'active') {
                        $effective = MediaPolicy::resolve(
                            isset($row['media_policy']) ? (string) $row['media_policy'] : null,
                            $this->db()
                        );
                        if ($effective === 'record') {
                            $effective = 'relay';
                        }
                        $row['_effective_media'] = $effective;
                        $prov->upsertSubscriber(
                            (string) $row['username'],
                            (string) $row['domain'],
                            (string) $row['ha1'],
                            (string) $row['ha1b'],
                            $row
                        );
                        $prov->syncSipPeerProfile(
                            (string) $row['username'],
                            $effective,
                            (int) ($row['max_channels'] ?? 2)
                        );
                    } else {
                        $prov->removeSubscriber((string) $row['username'], (string) $row['domain']);
                        $prov->removeSipPeerProfile((string) $row['username']);
                    }
                } catch (\Throwable $e) {
                    error_log('[ownswitch] OpenSIPS provision SIP user: ' . $e->getMessage());
                    App::session()->flash('warning', 'Saved, but OpenSIPS provisioning failed: ' . mb_substr($e->getMessage(), 0, 240));
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
                $prov->removeSipPeerProfile((string) $row['username']);
            } catch (\Throwable) {
                App::session()->flash('warning', 'Deleted locally, but OpenSIPS deprovisioning failed.');
            }
        }
    }

    protected function formValues(Request $r, ?array $existing, array $values): array
    {
        if (MediaPolicy::isDefault(isset($values['media_policy']) ? (string) $values['media_policy'] : null)) {
            $values['media_policy'] = '';
        }
        return $values;
    }

    public static function generatePassword(int $bytes = 15): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', 'Ab'), '=') . '7';
    }
}

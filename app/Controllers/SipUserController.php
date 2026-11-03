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
use Ownswitch\Support\SipPeerOptions;

/**
 * SIP Users — OpenSIPS auth, fixed-host port, media/record, and peer signalling params.
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
            $f[] = [
                'name' => 'customer_id',
                'label' => 'User',
                'type' => 'select',
                'required' => true,
                'options' => $this->customerOptions($r),
                'readonly_on_edit' => true,
                'list' => true,
                'section' => 'Account',
            ];
        }
        $routes = ['' => '— use Tariff trunk group —'];
        try {
            foreach ($this->db()->query('SELECT id, name FROM routing_plans ORDER BY name LIMIT 500')->fetchAll() as $p) {
                $routes[$p['id']] = $p['name'];
            }
        } catch (\Throwable) {
        }

        array_push($f,
            [
                'name' => 'username',
                'label' => 'SIP user',
                'required' => true,
                'max' => 64,
                'pattern' => '/^[A-Za-z0-9._-]{3,64}$/',
                'readonly_on_edit' => true,
                'list' => true,
                'section' => 'Account',
            ],
            [
                'name' => 'password',
                'label' => 'SIP password',
                'type' => 'password',
                'max' => 128,
                'help' => 'Blank = auto-generate (create) or keep current (edit). Min 12 if typed.',
                'section' => 'Account',
            ],
            [
                'name' => 'caller_id',
                'label' => 'CallerID',
                'max' => 32,
                'pattern' => '/^\+?[0-9]{3,30}$/',
                'list' => true,
                'help' => 'Default CallerID. Overridden if the selected Trunk has From user set.',
                'section' => 'Account',
            ],
            [
                'name' => 'status',
                'label' => 'Status',
                'type' => 'select',
                'required' => true,
                'options' => ['active' => 'Active', 'disabled' => 'Inactive'],
                'list' => true,
                'default' => 'active',
                'section' => 'Account',
            ],
            [
                'name' => 'notes',
                'label' => 'Description',
                'max' => 255,
                'section' => 'Account',
            ],

            [
                'name' => 'sip_host',
                'label' => 'Host',
                'max' => 190,
                'default' => 'dynamic',
                'list' => true,
                'help' => 'dynamic = REGISTER from any IP. Fixed IP → OpenSIPS address ACL (IP peer / vendor PBX).',
                'section' => 'Network',
            ],
            [
                'name' => 'sip_port',
                'label' => 'SIP port',
                'type' => 'int',
                'min' => 0,
                'max' => 65535,
                'default' => '5060',
                'help' => 'For fixed Host: match this signalling port in OpenSIPS address (0 = any port). Use when the vendor PBX uses a non-5060 port.',
                'section' => 'Network',
            ],
            [
                'name' => 'peer_type',
                'label' => 'Type',
                'type' => 'select',
                'options' => SipPeerOptions::PEER_TYPE,
                'default' => 'friend',
                'help' => 'friend = register+call; peer = IP/outbound; user = register only. Stored for dialplan/policy.',
                'section' => 'Network',
            ],
            [
                'name' => 'nat',
                'label' => 'NAT',
                'type' => 'select',
                'options' => SipPeerOptions::NAT,
                'default' => 'force_rport,comedia',
                'help' => 'OpenSIPS nathelper: force_rport / received. comedia ⇒ prefer media relay for symmetric RTP.',
                'section' => 'Network',
            ],
            [
                'name' => 'insecure',
                'label' => 'Insecure',
                'type' => 'select',
                'options' => SipPeerOptions::INSECURE,
                'default' => 'no',
                'help' => 'port/invite: trust matching address entry (typical for IP-auth PBX). Digest still required when Host=dynamic.',
                'section' => 'Network',
            ],
            [
                'name' => 'qualify',
                'label' => 'Qualify',
                'type' => 'select',
                'options' => SipPeerOptions::YES_NO,
                'default' => 'yes',
                'help' => 'Yes = OPTIONS reachability / latency probe for this endpoint when registered or fixed-host.',
                'section' => 'Network',
            ],

            [
                'name' => 'routing_plan_id',
                'label' => 'Trunk group',
                'type' => 'select',
                'options' => $routes,
                'list' => true,
                'help' => 'Overrides the Tariff trunk group for this SIP user when set.',
                'section' => 'Routing & media',
            ],
            [
                'name' => 'media_policy',
                'label' => 'Media / record',
                'type' => 'select',
                'options' => [
                    '' => 'Default (Settings)',
                    'direct' => 'Direct (no RTPengine)',
                    'relay' => 'Relay (RTPengine)',
                    'record' => 'Record (RTPengine + record)',
                ],
                'default' => '',
                'list' => true,
                'help' => 'Direct = bridge media end-to-end. Relay = RTPengine. Record = RTPengine with call recording.',
                'section' => 'Routing & media',
            ],
            [
                'name' => 'max_channels',
                'label' => 'Call limit',
                'type' => 'int',
                'min' => -1,
                'max' => 10000,
                'required' => true,
                'default' => '2',
                'list' => true,
                'help' => '-1 = unlimited concurrent calls',
                'section' => 'Routing & media',
            ],
            [
                'name' => 'dtmfmode',
                'label' => 'DTMF mode',
                'type' => 'select',
                'options' => SipPeerOptions::DTMF,
                'default' => 'RFC2833',
                'help' => 'Preferred DTMF. RFC2833 = telephone-event (recommended for OpenSIPS/RTPengine).',
                'section' => 'Routing & media',
            ],
            [
                'name' => 'codecs',
                'label' => 'Codecs',
                'type' => 'select',
                'options' => SipPeerOptions::CODECS,
                'default' => 'PCMU,PCMA',
                'help' => 'Allowed audio codecs (portal + ownswitch_sip profile for media policy).',
                'section' => 'Routing & media',
            ],
            [
                'name' => 'allowtransfer',
                'label' => 'Allow transfer',
                'type' => 'select',
                'options' => SipPeerOptions::YES_NO,
                'default' => 'no',
                'help' => 'Permit SIP REFER / attended transfer for this user.',
                'section' => 'Routing & media',
            ],

            ['name' => 'reg_status', 'label' => 'Registration', 'list' => true, 'list_only' => true, 'section' => 'Account'],
            ['name' => 'latency_ms', 'label' => 'Latency', 'list' => true, 'list_only' => true, 'section' => 'Account'],
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
            $qualify = strtolower((string) ($row['qualify'] ?? 'yes')) === 'yes';
            $reg = $regs[$user] ?? null;
            $host = trim((string) ($row['sip_host'] ?? 'dynamic'));
            $port = (int) ($row['sip_port'] ?? 5060);
            if ($port <= 0) {
                $port = 5060;
            }

            if ($reg !== null) {
                $row['reg_status'] = 'registered';
                if (!$qualify) {
                    $row['latency_ms'] = '—';
                    continue;
                }
                $uri = trim((string) ($reg['received'] ?? '')) !== ''
                    ? (string) $reg['received']
                    : (string) ($reg['contact'] ?? '');
                $ep = SipOptionsProbe::endpointFromUri($uri);
                $ms = $ep ? SipOptionsProbe::rttMs($ep['host'], $ep['port']) : null;
                $row['latency_ms'] = $ms ?? 'n/a';
                continue;
            }

            $row['reg_status'] = 'offline';
            if ($qualify && $host !== '' && strtolower($host) !== 'dynamic' && filter_var($host, FILTER_VALIDATE_IP)) {
                $ms = SipOptionsProbe::rttMs($host, $port);
                $row['latency_ms'] = $ms ?? 'n/a';
            } else {
                $row['latency_ms'] = '—';
            }
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
        }
        if (isset($data['sip_host'])) {
            $host = trim((string) $data['sip_host']);
            $data['sip_host'] = $host === '' ? 'dynamic' : $host;
        }
        if (array_key_exists('sip_port', $data)) {
            $p = (int) $data['sip_port'];
            $data['sip_port'] = ($p < 0 || $p > 65535) ? 5060 : $p;
        }
        $tg = $data['routing_plan_id'] ?? null;
        $data['routing_plan_id'] = ($tg === null || $tg === '') ? null : (int) $tg;

        if (isset($data['codecs']) && is_string($data['codecs']) && $data['codecs'] !== '') {
            $data['disallow'] = 'all';
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
                    . ($prov->initError() !== '' ? $prov->initError() : 'OPENSIPS_ENABLED off'));
            } else {
                try {
                    if ($row['status'] === 'active') {
                        $effective = MediaPolicy::resolve(
                            isset($row['media_policy']) ? (string) $row['media_policy'] : null,
                            $this->db()
                        );
                        // record stays record for OpenSIPS rtpengine recording path
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
                            $effective === 'record' ? 'record' : $effective,
                            (int) ($row['max_channels'] ?? 2),
                            $row
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
            $port = (int) ($row['sip_port'] ?? 5060);
            if ($port <= 0) {
                $port = 5060;
            }
            return "SIP user saved. Softphone: User={$row['username']}  Pass={$this->plainPassword}  "
                . "Domain/Realm={$row['domain']}  Server/Proxy={$host}  Port={$port}  Transport=UDP";
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

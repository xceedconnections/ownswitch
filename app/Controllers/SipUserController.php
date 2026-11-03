<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\App;
use Ownswitch\Http\Request;
use Ownswitch\Integrations\OpenSIPS\OpensipsProvisioner;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\SipAccountRepository;
use Ownswitch\Services\Sip\SipOptionsProbe;
use Ownswitch\Support\Crypto;
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
                'label' => 'CallerID number',
                'max' => 32,
                'pattern' => '/^\+?[0-9]{3,30}$/',
                'list' => true,
                'help' => 'Leave blank for passthrough (ViciDial / softphone From goes out). Used when mode = Fixed. Trunk From user still wins if set.',
                'section' => 'Account',
            ],
            [
                'name' => 'caller_id_mode',
                'label' => 'CallerID mode',
                'type' => 'select',
                'options' => SipPeerOptions::CALLER_ID_MODE,
                'default' => 'passthrough',
                'help' => 'Passthrough = whatever ViciDial/Eyebeam sends. Fixed = portal CallerID number. Display Name = use softphone Display Name as CLI (digits).',
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
                'help' => 'Signalling style when DTMF is allowed. RFC2833 = telephone-event (recommended).',
                'section' => 'Routing & media',
            ],
            [
                'name' => 'dtmf_pass',
                'label' => 'Allow DTMF (press 1)',
                'type' => 'select',
                'options' => SipPeerOptions::DTMF_PASS,
                'default' => 'yes',
                'help' => 'Block = OpenSIPS absorbs SIP INFO DTMF and strips telephone-event so keypad digits are not passed to the far end.',
                'section' => 'Routing & media',
            ],
            [
                'name' => 'codecs',
                'label' => 'Codecs',
                'type' => 'multiselect',
                'options' => SipPeerOptions::CODECS,
                'default' => 'PCMU,PCMA',
                'add_label' => '+ Add codec…',
                'wide' => true,
                'help' => 'Select one or more codecs (ulaw, alaw, G729, GSM, …). Chips can be removed with ×.',
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
        if (!$prov->enabled()) {
            App::session()->flash(
                'warning',
                'Cannot read OpenSIPS registrations: '
                . ($prov->initError() !== '' ? $prov->initError() : 'OPENSIPS_ENABLED off / DB credentials missing in ownswitch.env')
            );
        }
        $regs = $prov->enabled() ? $prov->registeredContacts() : [];
        foreach ($rows as &$row) {
            $user = strtolower(trim((string) ($row['username'] ?? '')));
            $qualify = strtolower((string) ($row['qualify'] ?? 'yes')) === 'yes';
            $reg = $regs[$user] ?? null;
            // usrloc may key contacts with domain suffix in some dumps
            if ($reg === null) {
                foreach ($regs as $k => $info) {
                    if ($k === $user || str_starts_with((string) $k, $user . '@') || str_ends_with((string) $k, '@' . $user)) {
                        $reg = $info;
                        break;
                    }
                    $c = strtolower((string) ($info['contact'] ?? ''));
                    if ($c !== '' && str_contains($c, 'sip:' . $user . '@')) {
                        $reg = $info;
                        break;
                    }
                }
            }
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
            try {
                $data['password_enc'] = Crypto::encrypt((string) $pw);
            } catch (\Throwable $e) {
                throw new \DomainException('Could not store SIP password: ' . $e->getMessage());
            }
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
        $cid = trim((string) ($data['caller_id'] ?? ''));
        $data['caller_id'] = $cid !== '' ? $cid : null;
        $mode = strtolower(trim((string) ($data['caller_id_mode'] ?? 'passthrough')));
        if (!isset(SipPeerOptions::CALLER_ID_MODE[$mode])) {
            $mode = $cid !== '' ? 'fixed' : 'passthrough';
        }
        // Fixed mode with empty number → treat as passthrough so ViciDial CLI is kept
        if ($mode === 'fixed' && $cid === '') {
            $mode = 'passthrough';
        }
        $data['caller_id_mode'] = $mode;
        $dp = strtolower(trim((string) ($data['dtmf_pass'] ?? 'yes')));
        $data['dtmf_pass'] = $dp === 'no' ? 'no' : 'yes';
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
                        if ($this->plainPassword !== null) {
                            $row['_plain_password'] = $this->plainPassword;
                        }
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
            $realm = (string) App::config('app.integrations.opensips.sip_domain', (string) $row['domain']);
            $proxy = trim((string) App::config('app.integrations.opensips.public_ip', ''));
            if ($proxy === '') {
                $proxy = $realm;
            }
            $port = (int) ($row['sip_port'] ?? 5060);
            if ($port <= 0) {
                $port = 5060;
            }
            return "SIP user saved. Softphone (no Proxy): User={$row['username']}  Pass={$this->plainPassword}  "
                . "Domain={$proxy} OR {$realm} OR any FQDN→{$proxy}  Port={$port} UDP. "
                . "Magnus-style: username+password+IP/FQDN — leave Outbound Proxy blank.";
        }
        if ($row && empty($row['password_enc'])) {
            App::session()->flash(
                'warning',
                'Re-save this SIP user with a password once so OpenSIPS can accept Domain=FQDN and Domain=IP (plaintext digest).'
            );
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

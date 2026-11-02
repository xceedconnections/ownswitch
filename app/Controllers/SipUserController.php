<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\App;
use Ownswitch\Http\Request;
use Ownswitch\Integrations\OpenSIPS\OpensipsProvisioner;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\SipAccountRepository;
use Ownswitch\Support\SipCodecs;
use Ownswitch\Support\SipPeerOptions;

final class SipUserController extends CrudController
{
    protected string $base = '/sip-users';
    protected string $singular = 'SIP User';
    protected string $plural = 'SIP Users';
    protected array $writeRoles = ['super_admin', 'admin', 'reseller', 'customer_owner', 'customer_tech'];
    protected string $emptyHint = 'No SIP users. Create an account so a phone or PBX can register.';

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
            $f[] = ['name' => 'customer_id', 'label' => 'User', 'type' => 'select', 'required' => true, 'options' => $this->customerOptions($r), 'readonly_on_edit' => true, 'list' => true, 'section' => 'General'];
        }
        $plans = ['' => '— inherit from tariff / user —'];
        foreach ($this->db()->query('SELECT id, name FROM routing_plans ORDER BY name LIMIT 500')->fetchAll() as $p) {
            $plans[(string) $p['id']] = $p['name'];
        }
        $yn = SipPeerOptions::YES_NO;
        array_push($f,
            // General
            ['name' => 'username', 'label' => 'SIP user', 'required' => true, 'max' => 64, 'pattern' => '/^[A-Za-z0-9._-]{3,64}$/', 'readonly_on_edit' => true, 'list' => true, 'section' => 'General'],
            ['name' => 'password', 'label' => 'SIP password', 'type' => 'password', 'max' => 128, 'help' => 'Blank = auto-generate (create) or keep current (edit). Min 12 if typed.', 'section' => 'General'],
            ['name' => 'caller_id', 'label' => 'CallerID', 'max' => 32, 'pattern' => '/^\+?[0-9]{3,30}$/', 'list' => true, 'section' => 'General'],
            ['name' => 'alias', 'label' => 'Alias', 'max' => 64, 'section' => 'General'],
            ['name' => 'disallow', 'label' => 'Disallow', 'max' => 64, 'default' => 'all', 'section' => 'General'],
            ['name' => 'codecs', 'label' => 'Codec', 'type' => 'multiselect', 'wide' => true, 'max' => 190, 'default' => 'PCMA,PCMU,G729', 'options' => SipCodecs::OPTIONS, 'add_label' => '+ Add codec…', 'section' => 'General'],
            ['name' => 'sip_host', 'label' => 'Host', 'max' => 190, 'default' => 'dynamic', 'list' => true, 'section' => 'General', 'help' => 'dynamic = register from any IP; or set a fixed IP for IP auth'],
            ['name' => 'videosupport', 'label' => 'Videosupport', 'type' => 'select', 'options' => $yn, 'default' => 'no', 'section' => 'General'],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['active' => 'Active', 'disabled' => 'Inactive'], 'list' => true, 'default' => 'active', 'section' => 'General'],

            // NAT
            ['name' => 'nat', 'label' => 'NAT', 'max' => 64, 'default' => 'force_rport,comedia', 'section' => 'NAT'],
            ['name' => 'directmedia', 'label' => 'Directmedia', 'type' => 'select', 'options' => $yn, 'default' => 'no', 'section' => 'NAT'],
            ['name' => 'qualify', 'label' => 'Qualify', 'type' => 'select', 'options' => $yn, 'default' => 'yes', 'section' => 'NAT'],

            // Additional
            ['name' => 'routing_plan_id', 'label' => 'Trunk groups', 'type' => 'select', 'options' => $plans, 'section' => 'Additional', 'help' => 'Optional force Trunk Group for this SIP user'],
            ['name' => 'context', 'label' => 'Context', 'max' => 64, 'default' => 'billing', 'section' => 'Additional'],
            ['name' => 'dtmfmode', 'label' => 'Dtmfmode', 'type' => 'select', 'options' => SipPeerOptions::DTMF, 'default' => 'RFC2833', 'section' => 'Additional'],
            ['name' => 'insecure', 'label' => 'Insecure', 'max' => 64, 'default' => 'no', 'section' => 'Additional'],
            ['name' => 'deny_acl', 'label' => 'Deny', 'max' => 255, 'section' => 'Additional'],
            ['name' => 'permit_acl', 'label' => 'Permit', 'max' => 255, 'section' => 'Additional'],
            ['name' => 'peer_type', 'label' => 'Type', 'type' => 'select', 'options' => SipPeerOptions::PEER_TYPE, 'default' => 'friend', 'section' => 'Additional'],
            ['name' => 'allowtransfer', 'label' => 'Allowtransfer', 'type' => 'select', 'options' => $yn, 'default' => 'no', 'section' => 'Additional'],
            ['name' => 'fake_ring', 'label' => 'Fake Ring', 'type' => 'select', 'options' => $yn, 'default' => 'no', 'section' => 'Additional'],
            ['name' => 'max_channels', 'label' => 'Call limit', 'type' => 'int', 'min' => 1, 'max' => 10000, 'required' => true, 'default' => '2', 'list' => true, 'section' => 'Additional'],
            ['name' => 'moh', 'label' => 'MOH', 'max' => 64, 'section' => 'Additional'],
            ['name' => 'addparameter', 'label' => 'Addparameter', 'max' => 255, 'placeholder' => 'Parameterdial', 'section' => 'Additional'],
            ['name' => 'notes', 'label' => 'Description', 'max' => 255, 'section' => 'Additional'],

            // Asterisk extra
            ['name' => 'asterisk_extra', 'label' => 'Parameters', 'type' => 'textarea', 'wide' => true, 'rows' => 8, 'max' => 8000, 'section' => 'Asterisk extra config'],
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
        if (array_key_exists('codecs', $data)) {
            $data['codecs'] = SipCodecs::toStorage($data['codecs']);
        } elseif ($existing === null) {
            $data['codecs'] = SipCodecs::toStorage('PCMA,PCMU,G729');
        }
        if (array_key_exists('routing_plan_id', $data)) {
            $tg = $data['routing_plan_id'];
            $data['routing_plan_id'] = ($tg === null || $tg === '') ? null : (int) $tg;
        }
        if (isset($data['sip_host'])) {
            $host = trim((string) $data['sip_host']);
            $data['sip_host'] = $host === '' ? 'dynamic' : $host;
        }
        foreach (['alias', 'deny_acl', 'permit_acl', 'moh', 'addparameter', 'asterisk_extra'] as $k) {
            if (array_key_exists($k, $data) && ($data[$k] === null || $data[$k] === '')) {
                $data[$k] = null;
            }
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
                    if ($row['status'] === 'active') {
                        $prov->upsertSubscriber(
                            (string) $row['username'],
                            (string) $row['domain'],
                            (string) $row['ha1'],
                            (string) $row['ha1b'],
                            $row
                        );
                    } else {
                        $prov->removeSubscriber((string) $row['username'], (string) $row['domain']);
                    }
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

    protected function formValues(Request $r, ?array $existing, array $values): array
    {
        if (array_key_exists('codecs', $values) || $existing === null) {
            $raw = $values['codecs'] ?? ($existing['codecs'] ?? 'PCMA,PCMU,G729');
            $values['codecs'] = SipCodecs::toStorage($raw) ?? 'PCMA,PCMU,G729';
        }
        if (($values['routing_plan_id'] ?? null) === null) {
            $values['routing_plan_id'] = '';
        }
        return $values;
    }

    public static function generatePassword(int $bytes = 15): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', 'Ab'), '=') . '7';
    }
}

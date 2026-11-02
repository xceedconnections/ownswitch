<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\App;
use Ownswitch\Http\Request;
use Ownswitch\Integrations\OpenSIPS\OpensipsProvisioner;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\CarrierRepository;
use Ownswitch\Services\Routing\OpensipsRoutingSync;
use Ownswitch\Support\Crypto;
use Ownswitch\Support\MediaPolicy;
use Ownswitch\Support\SipCodecs;

/**
 * Providers (kind=carrier) and SIP trunks (kind=trunk).
 *
 * Important: IP-to-IP trunks do NOT REGISTER to the vendor. The vendor must allowlist
 * this switch's public SIP IP. Use auth_mode=register only when the vendor gave a
 * SIP username/password that must REGISTER (Magnus "User" style).
 */
final class CarrierController extends CrudController
{
    public function __construct(private string $kind = 'carrier')
    {
        $trunk = $kind === 'trunk';
        $this->base = $trunk ? '/trunks' : '/providers';
        $this->singular = $trunk ? 'Trunk' : 'Provider';
        $this->plural = $trunk ? 'Trunks' : 'Providers';
        $this->emptyHint = $trunk
            ? 'No trunks yet. IP peering: set Auth mode = IP peer (vendor allowlists your public IP). Digest account: Auth mode = Register + username/password.'
            : 'No providers yet. Add the upstream carrier you buy minutes from, then add Trunks.';
    }

    protected function repo(): BaseRepository
    {
        return $this->kind === 'trunk' ? CarrierRepository::trunks($this->db()) : CarrierRepository::carriers($this->db());
    }

    protected function filterColumns(): array
    {
        return $this->kind === 'trunk' ? ['carrier_id', 'status', 'auth_mode'] : ['status'];
    }

    protected function fields(Request $r): array
    {
        $status = ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['active' => 'Active', 'disabled' => 'Disabled'], 'list' => true];
        if ($this->kind !== 'trunk') {
            // Magnus Providers: Name, Description (+ Active)
            return [
                ['name' => 'name', 'label' => 'Name', 'required' => true, 'max' => 120, 'list' => true],
                ['name' => 'notes', 'label' => 'Description', 'type' => 'textarea', 'max' => 2000],
                $status,
            ];
        }
        $carriers = [];
        foreach ($this->db()->query('SELECT id, name FROM carriers ORDER BY name LIMIT 500')->fetchAll() as $c) {
            $carriers[$c['id']] = $c['name'];
        }
        // Magnus Trunks: Provider, Name, Username, Password, Host, Codec, Register trunk, Status
        return [
            ['name' => 'carrier_id', 'label' => 'Provider', 'type' => 'select', 'required' => true, 'options' => $carriers, 'list' => true],
            ['name' => 'name', 'label' => 'Name', 'required' => true, 'max' => 120, 'list' => true],
            ['name' => 'host', 'label' => 'Host', 'required' => true, 'max' => 190, 'pattern' => '/^[A-Za-z0-9.\-:\[\]]{1,190}$/', 'list' => true],
            ['name' => 'port', 'label' => 'Port', 'type' => 'int', 'min' => 1, 'max' => 65535, 'required' => true, 'default' => '5060', 'list' => true],
            ['name' => 'transport', 'label' => 'Transport', 'type' => 'select', 'required' => true, 'options' => ['udp' => 'UDP', 'tcp' => 'TCP', 'tls' => 'TLS'], 'default' => 'udp'],
            ['name' => 'auth_mode', 'label' => 'Register trunk', 'type' => 'select', 'required' => true, 'list' => true, 'default' => 'ip', 'options' => [
                'ip' => 'No (IP peer)',
                'register' => 'Yes (user/pass REGISTER)',
                'invite' => 'Invite digest only',
            ]],
            ['name' => 'auth_username', 'label' => 'Username', 'max' => 120],
            ['name' => 'auth_secret', 'label' => 'Password', 'type' => 'password', 'max' => 190, 'help' => 'Leave blank on edit to keep current.'],
            ['name' => 'codecs', 'label' => 'Codec', 'type' => 'multiselect', 'wide' => true, 'max' => 190, 'default' => 'PCMA,PCMU', 'options' => SipCodecs::OPTIONS],
            ['name' => 'max_channels', 'label' => 'Call limit', 'type' => 'int', 'min' => 1, 'max' => 1000000, 'required' => true, 'default' => '100', 'list' => true],
            $status,
        ];
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        if ($this->kind === 'trunk') {
            $secret = $data['auth_secret'] ?? null;
            unset($data['auth_secret']);
            if ($secret !== null && $secret !== '') {
                try {
                    $data['auth_secret_enc'] = Crypto::encrypt((string) $secret);
                } catch (\RuntimeException $e) {
                    throw new \DomainException('Cannot store the secret: ' . $e->getMessage());
                }
            }
            if ($existing === null) {
                $data['priority'] = $data['priority'] ?? 100;
                $data['connect_fee'] = $data['connect_fee'] ?? '0';
                $data['initial_increment'] = $data['initial_increment'] ?? 6;
                $data['subsequent_increment'] = $data['subsequent_increment'] ?? 6;
                $data['media_policy'] = null; // inherit Settings default
            }
            if (array_key_exists('codecs', $data)) {
                $data['codecs'] = SipCodecs::toStorage($data['codecs']);
            } elseif ($existing === null) {
                $data['codecs'] = SipCodecs::toStorage('PCMA,PCMU');
            }
            $mode = (string) ($data['auth_mode'] ?? 'ip');
            if (in_array($mode, ['register', 'invite'], true)) {
                $user = trim((string) ($data['auth_username'] ?? ($existing['auth_username'] ?? '')));
                $hasSecret = !empty($data['auth_secret_enc']) || !empty($existing['auth_secret_enc'] ?? null);
                if ($user === '' || (!$hasSecret && $existing === null)) {
                    throw new \DomainException('Register / Invite digest modes require Username and Password.');
                }
            }
        } else {
            $data = $this->autoCode($data, $existing, 'carriers');
            $data['is_demo'] = $existing['is_demo'] ?? 0;
        }
        return $data;
    }

    protected function afterSave(Request $r, int $id, array $data, ?array $existing): ?string
    {
        if ($this->kind !== 'trunk') {
            return null;
        }
        $prov = OpensipsProvisioner::fromConfig();
        if (!$prov->enabled()) {
            return 'Trunk saved in portal only — OpenSIPS provisioning is disabled. Enable OPENSIPS_ENABLED and re-save, or run: php bin/sync-opensips-trunks.php';
        }
        try {
            $row = $this->repo()->findInternal($id);
            if ($row) {
                $prov->syncTrunk($row);
            }
            $sync = (new OpensipsRoutingSync($this->db(), $prov))->syncAll();
            if ($sync['warnings'] !== []) {
                App::session()->flash('warning', 'Trunk saved with warnings: ' . mb_substr(implode(' | ', $sync['warnings']), 0, 300));
            }
            $mode = (string) ($row['auth_mode'] ?? 'ip');
            if ($mode === 'ip') {
                return 'Trunk pushed to OpenSIPS. IP peer mode: ask your vendor to allowlist THIS server public SIP IP on their Magnus (IP authentication). They will not show “Registered”.';
            }
            if ($mode === 'register') {
                return 'Trunk pushed to OpenSIPS with outbound REGISTER. Check vendor Magnus after ~30s; if still offline, verify user/pass/host and opensips logs.';
            }
            return 'Trunk pushed to OpenSIPS (invite digest).';
        } catch (\Throwable $e) {
            error_log('[ownswitch] OpenSIPS trunk sync: ' . $e->getMessage());
            $hint = preg_replace('/\s+/', ' ', $e->getMessage()) ?? $e->getMessage();
            App::session()->flash('warning', 'Saved in portal, but OpenSIPS sync failed: ' . mb_substr($hint, 0, 280)
                . ' — run: sudo bash /opt/ownswitch-src/deploy/fix-drouting-schema.sh');
            return null;
        }
    }

    protected function afterDelete(Request $r, array $row): void
    {
        if ($this->kind !== 'trunk') {
            return;
        }
        $prov = OpensipsProvisioner::fromConfig();
        if ($prov->enabled()) {
            try {
                $prov->removeTrunk((int) $row['id']);
                (new OpensipsRoutingSync($this->db(), $prov))->syncAll();
            } catch (\Throwable) {
                App::session()->flash('warning', 'Deleted locally, but OpenSIPS deprovisioning failed.');
            }
        }
    }

    protected function formValues(Request $r, ?array $existing, array $values): array
    {
        if ($this->kind !== 'trunk') {
            return $values;
        }
        if (array_key_exists('codecs', $values) || $existing === null) {
            $raw = $values['codecs'] ?? ($existing['codecs'] ?? 'PCMA,PCMU');
            $values['codecs'] = SipCodecs::toStorage($raw) ?? 'PCMA,PCMU';
        }
        if (MediaPolicy::isDefault(isset($values['media_policy']) ? (string) $values['media_policy'] : null)) {
            $values['media_policy'] = '';
        }
        return $values;
    }
}

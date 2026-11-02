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
use Ownswitch\Support\SipPeerOptions;

/**
 * Providers + Trunks — OpenSIPS drouting / registrant fields only (no Asterisk peer junk).
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
            ? 'No trunks. OpenSIPS: Host/port, optional digest REGISTER, strip/prefix, From rewrite, probe.'
            : 'No providers yet. Add the upstream carrier, then add Trunks.';
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
        return [
            ['name' => 'carrier_id', 'label' => 'Provider', 'type' => 'select', 'required' => true, 'options' => $carriers, 'list' => true],
            ['name' => 'name', 'label' => 'Name', 'required' => true, 'max' => 120, 'list' => true],
            ['name' => 'host', 'label' => 'Host', 'required' => true, 'max' => 190, 'pattern' => '/^[A-Za-z0-9.\-:\[\]]{1,190}$/', 'list' => true],
            ['name' => 'port', 'label' => 'Port', 'type' => 'int', 'min' => 1, 'max' => 65535, 'required' => true, 'default' => '5060', 'list' => true],
            ['name' => 'transport', 'label' => 'Transport', 'type' => 'select', 'required' => true, 'options' => ['udp' => 'UDP', 'tcp' => 'TCP', 'tls' => 'TLS'], 'default' => 'udp'],
            ['name' => 'auth_mode', 'label' => 'Auth mode', 'type' => 'select', 'required' => true, 'list' => true, 'default' => 'ip', 'options' => [
                'ip' => 'IP peer (vendor allowlists us)',
                'register' => 'Outbound REGISTER (user/pass)',
                'invite' => 'Invite digest only',
            ], 'help' => 'OpenSIPS: IP → address table; Register → uac_registrant'],
            ['name' => 'auth_username', 'label' => 'Username', 'max' => 120],
            ['name' => 'auth_secret', 'label' => 'Password', 'type' => 'password', 'max' => 190, 'help' => 'Leave blank on edit to keep current.'],
            ['name' => 'add_prefix', 'label' => 'Add prefix', 'max' => 32, 'help' => 'OpenSIPS dr_gateways.pri_prefix'],
            ['name' => 'remove_prefix', 'label' => 'Remove prefix', 'max' => 32, 'help' => 'OpenSIPS dr_gateways.strip (digit count)'],
            ['name' => 'from_user', 'label' => 'From user', 'max' => 64, 'help' => 'Rewrite outbound From user'],
            ['name' => 'from_domain', 'label' => 'From domain', 'max' => 128],
            ['name' => 'cid_add_prefix', 'label' => 'CID add prefix', 'max' => 32],
            ['name' => 'cid_remove_prefix', 'label' => 'CID remove prefix', 'max' => 32],
            ['name' => 'qualify', 'label' => 'Probe gateway', 'type' => 'select', 'options' => SipPeerOptions::YES_NO, 'default' => 'yes', 'help' => 'OpenSIPS probe_mode'],
            ['name' => 'max_channels', 'label' => 'Max channels', 'type' => 'int', 'min' => 0, 'max' => 1000000, 'required' => true, 'default' => '100', 'list' => true, 'help' => 'Soft limit for portal/reporting'],
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
                $data['media_policy'] = null;
            }
            foreach (['add_prefix', 'remove_prefix', 'from_user', 'from_domain', 'cid_add_prefix', 'cid_remove_prefix'] as $k) {
                if (array_key_exists($k, $data) && ($data[$k] === null || $data[$k] === '')) {
                    $data[$k] = null;
                }
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
            return 'Trunk saved in portal only — OpenSIPS provisioning disabled.';
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
            return 'Trunk pushed to OpenSIPS (gateway + routes + auth).';
        } catch (\Throwable $e) {
            error_log('[ownswitch] OpenSIPS trunk sync: ' . $e->getMessage());
            App::session()->flash('warning', 'Saved, but OpenSIPS sync failed: ' . mb_substr($e->getMessage(), 0, 280));
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
}

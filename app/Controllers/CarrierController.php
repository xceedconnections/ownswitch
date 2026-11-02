<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\CarrierRepository;
use Ownswitch\Support\Crypto;

/**
 * Providers (kind=carrier) and their SIP trunks (kind=trunk).
 * UI naming matches MagnusBilling "Provider" / "Trunk".
 */
final class CarrierController extends CrudController
{
    public function __construct(private string $kind = 'carrier')
    {
        $trunk = $kind === 'trunk';
        $this->base = $trunk ? '/trunks' : '/carriers';
        $this->singular = $trunk ? 'Trunk' : 'Provider';
        $this->plural = $trunk ? 'Trunks' : 'Providers';
        $this->emptyHint = $trunk
            ? 'No trunks yet. Add a Provider first, then create a Trunk with host, user and password.'
            : 'No providers yet. Add the upstream carrier/provider you buy minutes from, then add Trunks.';
    }

    protected function repo(): BaseRepository
    {
        return $this->kind === 'trunk' ? CarrierRepository::trunks($this->db()) : CarrierRepository::carriers($this->db());
    }

    protected function filterColumns(): array
    {
        return $this->kind === 'trunk' ? ['carrier_id', 'status'] : ['status'];
    }

    protected function fields(Request $r): array
    {
        $status = ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['active' => 'Active', 'disabled' => 'Disabled'], 'list' => true];
        if ($this->kind !== 'trunk') {
            return [
                ['name' => 'name', 'label' => 'Provider name', 'required' => true, 'max' => 120, 'list' => true, 'help' => 'e.g. Voxbone, Bandwidth, Local DID provider'],
                ['name' => 'contact_email', 'label' => 'Contact email', 'type' => 'email', 'max' => 190],
                $status,
                ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'max' => 2000],
            ];
        }
        $carriers = [];
        foreach ($this->db()->query('SELECT id, name FROM carriers ORDER BY name LIMIT 500')->fetchAll() as $c) {
            $carriers[$c['id']] = $c['name'];
        }
        return [
            ['name' => 'carrier_id', 'label' => 'Provider', 'type' => 'select', 'required' => true, 'options' => $carriers, 'list' => true],
            ['name' => 'name', 'label' => 'Trunk name', 'required' => true, 'max' => 120, 'list' => true, 'help' => 'Friendly name, e.g. Provider-Main'],
            ['name' => 'host', 'label' => 'SIP host / IP', 'required' => true, 'max' => 190, 'pattern' => '/^[A-Za-z0-9.\-:\[\]]{1,190}$/', 'list' => true],
            ['name' => 'port', 'label' => 'Port', 'type' => 'int', 'min' => 1, 'max' => 65535, 'required' => true, 'default' => '5060', 'list' => true],
            ['name' => 'transport', 'label' => 'Transport', 'type' => 'select', 'required' => true, 'options' => ['udp' => 'UDP', 'tcp' => 'TCP', 'tls' => 'TLS'], 'default' => 'udp'],
            ['name' => 'auth_username', 'label' => 'Username', 'max' => 120, 'help' => 'Leave blank for IP-authenticated trunks'],
            ['name' => 'auth_secret', 'label' => 'Password', 'type' => 'password', 'max' => 190, 'help' => 'Encrypted at rest. Leave blank on edit to keep the current password.'],
            ['name' => 'max_channels', 'label' => 'Max channels', 'type' => 'int', 'min' => 1, 'max' => 1000000, 'required' => true, 'default' => '100'],
            ['name' => 'codecs', 'label' => 'Codecs', 'max' => 190, 'default' => 'PCMA,PCMU', 'help' => 'Comma-separated, e.g. PCMA,PCMU,G729'],
            ['name' => 'media_policy', 'label' => 'Media', 'type' => 'select', 'options' => ['' => 'Inherit', 'direct' => 'Direct', 'relay' => 'Relay', 'record' => 'Record']],
            ['name' => 'initial_increment', 'label' => 'Buy increment I (seconds)', 'type' => 'int', 'min' => 1, 'max' => 86400, 'required' => true, 'default' => '6', 'help' => 'Provider billing: first block'],
            ['name' => 'subsequent_increment', 'label' => 'Buy increment S (seconds)', 'type' => 'int', 'min' => 1, 'max' => 86400, 'required' => true, 'default' => '6', 'help' => 'Provider billing: next blocks'],
            ['name' => 'default_rate', 'label' => 'Fallback buy rate / min', 'type' => 'number', 'help' => 'Optional if you set Provider Rates per prefix'],
            ['name' => 'connect_fee', 'label' => 'Buy connection fee', 'type' => 'number', 'default' => '0'],
            ['name' => 'priority', 'label' => 'Priority', 'type' => 'int', 'min' => 0, 'max' => 100000, 'required' => true, 'default' => '100', 'list' => true, 'help' => 'Lower number = tried first'],
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
            $data['connect_fee'] = $data['connect_fee'] ?? '0';
        } else {
            $data = $this->autoCode($data, $existing, 'carriers');
            $data['is_demo'] = $existing['is_demo'] ?? 0;
        }
        return $data;
    }
}

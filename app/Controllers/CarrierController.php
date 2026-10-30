<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\CarrierRepository;
use Ownswitch\Support\Crypto;

/** Carriers (kind=carrier) and their SIP trunks (kind=trunk). */
final class CarrierController extends CrudController
{
    public function __construct(private string $kind = 'carrier')
    {
        $trunk = $kind === 'trunk';
        $this->base = $trunk ? '/trunks' : '/carriers';
        $this->singular = $trunk ? 'Trunk' : 'Carrier';
        $this->plural = $trunk ? 'Trunks' : 'Carriers';
        $this->emptyHint = $trunk
            ? 'No trunks. Add a carrier first, then define its SIP trunk(s) with carrier-side increments.'
            : 'No carriers. Add the upstream providers you buy termination from.';
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
                ['name' => 'code', 'label' => 'Code', 'required' => true, 'max' => 32, 'pattern' => '/^[A-Za-z0-9_-]{2,32}$/', 'readonly_on_edit' => true, 'list' => true],
                ['name' => 'name', 'label' => 'Name', 'required' => true, 'max' => 120, 'list' => true],
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
            ['name' => 'carrier_id', 'label' => 'Carrier', 'type' => 'select', 'required' => true, 'options' => $carriers, 'list' => true],
            ['name' => 'name', 'label' => 'Trunk name', 'required' => true, 'max' => 120, 'list' => true],
            ['name' => 'host', 'label' => 'Host / IP', 'required' => true, 'max' => 190, 'pattern' => '/^[A-Za-z0-9.\-:\[\]]{1,190}$/', 'list' => true],
            ['name' => 'port', 'label' => 'Port', 'type' => 'int', 'min' => 1, 'max' => 65535, 'required' => true, 'default' => '5060', 'list' => true],
            ['name' => 'transport', 'label' => 'Transport', 'type' => 'select', 'required' => true, 'options' => ['udp' => 'UDP', 'tcp' => 'TCP', 'tls' => 'TLS']],
            ['name' => 'auth_username', 'label' => 'Auth username', 'max' => 120],
            ['name' => 'auth_secret', 'label' => 'Auth password', 'type' => 'password', 'max' => 190, 'help' => 'Stored encrypted (AES-256-GCM). Leave blank to keep the current secret. Requires APP_KEY.'],
            ['name' => 'max_channels', 'label' => 'Max channels', 'type' => 'int', 'min' => 1, 'max' => 1000000, 'required' => true, 'default' => '100'],
            ['name' => 'codecs', 'label' => 'Codecs', 'max' => 190, 'help' => 'e.g. PCMA,PCMU,G729'],
            ['name' => 'media_policy', 'label' => 'Media policy', 'type' => 'select', 'options' => ['' => '- inherit -', 'direct' => 'Direct', 'relay' => 'Relay', 'record' => 'Record']],
            ['name' => 'initial_increment', 'label' => 'Carrier initial increment I (s)', 'type' => 'int', 'min' => 1, 'max' => 86400, 'required' => true, 'default' => '6'],
            ['name' => 'subsequent_increment', 'label' => 'Carrier subsequent increment S (s)', 'type' => 'int', 'min' => 1, 'max' => 86400, 'required' => true, 'default' => '6'],
            ['name' => 'default_rate', 'label' => 'Default rate / min (fallback)', 'type' => 'number'],
            ['name' => 'connect_fee', 'label' => 'Connection fee', 'type' => 'number', 'default' => '0'],
            ['name' => 'priority', 'label' => 'Priority (lower first)', 'type' => 'int', 'min' => 0, 'max' => 100000, 'required' => true, 'default' => '100', 'list' => true],
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
            $data['is_demo'] = $existing['is_demo'] ?? 0;
        }
        return $data;
    }
}

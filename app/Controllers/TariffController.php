<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\PrefixRepository;
use Ownswitch\Repositories\RatePlanRepository;
use Ownswitch\Repositories\TariffRepository;

/**
 * Magnus Tariffs / Provider Rates field set.
 * Sell: Plan, Destination, Trunk group, Sell price, Initial/Billing block, Connection charge, Status.
 * Buy: Provider, Destination, Buy price, Initial/Billing block, Status.
 */
final class TariffController extends CrudController
{
    public function __construct(private string $kind = 'customer')
    {
        $carrier = $kind === 'carrier';
        $this->base = $carrier ? '/carrier-tariffs' : '/tariffs';
        $this->singular = $carrier ? 'Provider rate' : 'Tariff';
        $this->plural = $carrier ? 'Provider Rates' : 'Tariffs';
        $this->emptyHint = $carrier
            ? 'No provider rates. Magnus: Provider + Destination + buy price.'
            : 'No tariffs yet. Magnus: Plan + Destination + Trunk group + sell price + blocks.';
    }

    protected function repo(): BaseRepository
    {
        return $this->kind === 'carrier' ? TariffRepository::carrier($this->db()) : TariffRepository::customer($this->db());
    }

    protected function filterColumns(): array
    {
        return [$this->kind === 'carrier' ? 'carrier_id' : 'rate_plan_id', 'status'];
    }

    protected function fields(Request $r): array
    {
        $carrier = $this->kind === 'carrier';
        $opts = [];
        $sql = $carrier
            ? 'SELECT id, name FROM carriers ORDER BY name LIMIT 500'
            : 'SELECT id, name FROM rate_plans ORDER BY name LIMIT 500';
        foreach ($this->db()->query($sql)->fetchAll() as $x) {
            $opts[$x['id']] = $x['name'];
        }
        $prefixes = (new PrefixRepository($this->db()))->options();
        $fields = [
            [
                'name' => $carrier ? 'carrier_id' : 'rate_plan_id',
                'label' => $carrier ? 'Provider' : 'Plan',
                'type' => 'select',
                'required' => true,
                'options' => $opts,
                'readonly_on_edit' => true,
                'list' => true,
            ],
            [
                'name' => 'prefix',
                'label' => 'Destination',
                'type' => 'select',
                'required' => true,
                'options' => $prefixes,
                'readonly_on_edit' => true,
                'list' => true,
                'wide' => true,
                'help' => $prefixes === [] ? 'Create Prefixes first (Rates → Prefixes).' : 'From Rates → Prefixes.',
            ],
        ];
        if (!$carrier) {
            $groups = ['' => '— use user’s Trunk Group —'];
            foreach ($this->db()->query('SELECT id, name FROM routing_plans ORDER BY name LIMIT 500')->fetchAll() as $g) {
                $groups[$g['id']] = $g['name'];
            }
            $fields[] = [
                'name' => 'routing_plan_id',
                'label' => 'Trunk groups',
                'type' => 'select',
                'options' => $groups,
                'list' => true,
                'help' => 'Trunks used to send this call. Blank = user’s Trunk Group.',
            ];
        }
        return array_merge($fields, [
            [
                'name' => 'rate',
                'label' => $carrier ? 'Buy price' : 'Sell price',
                'type' => 'number',
                'required' => true,
                'list' => true,
                'help' => 'Per minute',
            ],
            ['name' => 'initial_increment', 'label' => 'Initial block', 'type' => 'int', 'min' => 1, 'max' => 86400, 'default' => '6', 'list' => true, 'help' => 'Seconds'],
            ['name' => 'subsequent_increment', 'label' => 'Billing block', 'type' => 'int', 'min' => 1, 'max' => 86400, 'default' => '6', 'list' => true, 'help' => 'Seconds'],
            ['name' => 'connect_fee', 'label' => 'Connection charge', 'type' => 'number', 'default' => '0'],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['active' => 'Active', 'disabled' => 'Inactive'], 'list' => true, 'default' => 'active'],
        ]);
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        $prefix = (string) ($data['prefix'] ?? ($existing['prefix'] ?? ''));
        $dest = (new PrefixRepository($this->db()))->destinationFor($prefix);
        if ($dest === null) {
            throw new \DomainException('Select a Destination from Prefixes.');
        }
        $data['prefix'] = $prefix;
        $data['destination'] = $dest;
        if ($this->kind === 'customer') {
            $tg = $data['routing_plan_id'] ?? null;
            $data['routing_plan_id'] = ($tg === null || $tg === '') ? null : (int) $tg;
        }
        if (($data['initial_increment'] ?? null) === null || $data['initial_increment'] === '') {
            $data['initial_increment'] = 6;
        }
        if (($data['subsequent_increment'] ?? null) === null || $data['subsequent_increment'] === '') {
            $data['subsequent_increment'] = 6;
        }
        if (($data['connect_fee'] ?? null) === null || $data['connect_fee'] === '') {
            $data['connect_fee'] = '0';
        }
        return $data;
    }

    protected function afterSave(Request $r, int $id, array $data, ?array $existing): ?string
    {
        if ($this->kind === 'customer') {
            $row = $this->repo()->findInternal($id);
            if ($row) {
                (new RatePlanRepository($this->db()))->createVersion((int) $row['rate_plan_id'], (int) ($this->user($r)['id'] ?? 0) ?: null);
            }
        }
        return null;
    }

    protected function afterDelete(Request $r, array $row): void
    {
        if ($this->kind === 'customer') {
            (new RatePlanRepository($this->db()))->createVersion((int) $row['rate_plan_id'], (int) ($this->user($r)['id'] ?? 0) ?: null);
        }
    }
}

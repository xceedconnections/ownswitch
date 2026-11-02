<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\PrefixRepository;
use Ownswitch\Repositories\RatePlanRepository;
use Ownswitch\Repositories\TariffRepository;

/**
 * MagnusBilling-style Tariffs (sell) or Provider Rates (buy).
 * Destination is chosen from the global Prefixes list (prefix + name only).
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
            ? 'No provider rates. Add buy rates per Prefix for each Provider (independent of customer sell rates).'
            : 'No tariffs yet. Magnus flow: create Prefixes first, then add a Tariff (Plan + Destination + sell price + billing blocks).';
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
        $pattern = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/';
        return [
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
                'help' => $prefixes === []
                    ? 'No Prefixes yet — create them under Rates → Prefixes first.'
                    : 'Pick a Prefix (dial code + name). Rates are not stored on the Prefix itself.',
            ],
            [
                'name' => 'rate',
                'label' => $carrier ? 'Buy price / min' : 'Sell price / min',
                'type' => 'number',
                'required' => true,
                'list' => true,
            ],
            ['name' => 'initial_increment', 'label' => 'Initial block (s)', 'type' => 'int', 'min' => 1, 'max' => 86400, 'list' => true, 'help' => 'Blank = use plan / trunk default'],
            ['name' => 'subsequent_increment', 'label' => 'Billing block (s)', 'type' => 'int', 'min' => 1, 'max' => 86400, 'list' => true],
            ['name' => 'connect_fee', 'label' => 'Connection charge', 'type' => 'number', 'help' => 'Blank = inherit'],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['active' => 'Active', 'disabled' => 'Disabled'], 'list' => true],
            ['name' => 'effective_from', 'label' => 'Valid from (UTC)', 'pattern' => $pattern, 'help' => 'Optional YYYY-MM-DD'],
            ['name' => 'effective_to', 'label' => 'Valid to (UTC)', 'pattern' => $pattern],
        ];
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        $prefix = (string) ($data['prefix'] ?? ($existing['prefix'] ?? ''));
        $dest = (new PrefixRepository($this->db()))->destinationFor($prefix);
        if ($dest === null) {
            throw new \DomainException('Select a Destination from Prefixes (Rates → Prefixes).');
        }
        $data['prefix'] = $prefix;
        $data['destination'] = $dest;
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

<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\RatePlanRepository;
use Ownswitch\Repositories\TariffRepository;

/** Customer sell tariffs (kind=customer) or carrier buy tariffs (kind=carrier). I and S are independent per side. */
final class TariffController extends CrudController
{
    public function __construct(private string $kind = 'customer')
    {
        $carrier = $kind === 'carrier';
        $this->base = $carrier ? '/carrier-tariffs' : '/tariffs';
        $this->singular = $carrier ? 'Carrier tariff' : 'Customer tariff';
        $this->plural = $carrier ? 'Carrier Tariffs' : 'Customer Tariffs';
        $this->emptyHint = $carrier
            ? 'No carrier tariffs. Add purchase rates per prefix; increments here are the CARRIER side and independent of customer increments.'
            : 'No customer tariffs. Add per-prefix sell rates to a rate plan. Blank increments inherit the plan defaults.';
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
        $sql = $carrier ? 'SELECT id, code, name FROM carriers ORDER BY name LIMIT 500' : 'SELECT id, code, name FROM rate_plans ORDER BY name LIMIT 500';
        foreach ($this->db()->query($sql)->fetchAll() as $x) {
            $opts[$x['id']] = "{$x['name']} ({$x['code']})";
        }
        $pattern = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/';
        return [
            ['name' => $carrier ? 'carrier_id' : 'rate_plan_id', 'label' => $carrier ? 'Carrier' : 'Rate plan', 'type' => 'select', 'required' => true, 'options' => $opts, 'readonly_on_edit' => true, 'list' => true],
            ['name' => 'prefix', 'label' => 'Prefix', 'type' => 'prefix', 'required' => true, 'readonly_on_edit' => true, 'list' => true, 'help' => 'Digits only, E.164 without +. Longest prefix wins.'],
            ['name' => 'destination', 'label' => 'Destination', 'max' => 120, 'list' => true],
            ['name' => 'rate', 'label' => 'Rate per minute', 'type' => 'number', 'required' => true, 'list' => true],
            ['name' => 'initial_increment', 'label' => 'Initial increment I (s)', 'type' => 'int', 'min' => 1, 'max' => 86400, 'list' => true, 'help' => 'Blank = inherit'],
            ['name' => 'subsequent_increment', 'label' => 'Subsequent increment S (s)', 'type' => 'int', 'min' => 1, 'max' => 86400, 'list' => true],
            ['name' => 'connect_fee', 'label' => 'Connection fee', 'type' => 'number', 'help' => 'Blank = inherit'],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['active' => 'Active', 'disabled' => 'Disabled'], 'list' => true],
            ['name' => 'effective_from', 'label' => 'Effective from (UTC)', 'pattern' => $pattern, 'help' => 'YYYY-MM-DD [HH:MM[:SS]]'],
            ['name' => 'effective_to', 'label' => 'Effective to (UTC)', 'pattern' => $pattern],
        ];
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        // Increments must come in a pair or not at all? No: a blank half inherits independently. Nothing to enforce.
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

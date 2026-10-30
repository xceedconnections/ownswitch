<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\RatePlanRepository;

final class RatePlanController extends CrudController
{
    protected string $base = '/rate-plans';
    protected string $singular = 'Rate plan';
    protected string $plural = 'Rate Plans';
    protected string $emptyHint = 'No rate plans. Create a plan with default increments (I/S), then add per-prefix tariffs. Each change creates an immutable plan version.';

    protected function repo(): BaseRepository
    {
        return new RatePlanRepository($this->db());
    }

    protected function filterColumns(): array
    {
        return ['status'];
    }

    protected function fields(Request $r): array
    {
        return [
            ['name' => 'code', 'label' => 'Code', 'required' => true, 'max' => 32, 'pattern' => '/^[A-Za-z0-9_-]{2,32}$/', 'readonly_on_edit' => true, 'list' => true],
            ['name' => 'name', 'label' => 'Name', 'required' => true, 'max' => 120, 'list' => true],
            ['name' => 'currency', 'label' => 'Currency', 'required' => true, 'max' => 3, 'pattern' => '/^[A-Z]{3}$/', 'default' => 'USD', 'list' => true],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['active' => 'Active', 'disabled' => 'Disabled'], 'list' => true],
            ['name' => 'default_rate', 'label' => 'Default rate / min (fallback, optional)', 'type' => 'number', 'help' => 'Used only when no tariff prefix matches. Leave blank to reject unmatched destinations.'],
            ['name' => 'initial_increment', 'label' => 'Initial increment I (s)', 'type' => 'int', 'min' => 1, 'max' => 86400, 'required' => true, 'default' => '6', 'list' => true],
            ['name' => 'subsequent_increment', 'label' => 'Subsequent increment S (s)', 'type' => 'int', 'min' => 1, 'max' => 86400, 'required' => true, 'default' => '6', 'list' => true],
            ['name' => 'connect_fee', 'label' => 'Connection fee', 'type' => 'number', 'default' => '0'],
            ['name' => 'min_billable_seconds', 'label' => 'Minimum billable seconds', 'type' => 'int', 'min' => 0, 'max' => 86400, 'default' => '0'],
            ['name' => 'charge_connect_on_zero', 'label' => 'Charge connection fee on zero-duration calls', 'type' => 'checkbox'],
        ];
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        $data['connect_fee'] = $data['connect_fee'] ?? '0';
        $data['min_billable_seconds'] = $data['min_billable_seconds'] ?? 0;
        $data['is_demo'] = $existing['is_demo'] ?? 0;
        return $data;
    }

    protected function afterSave(Request $r, int $id, array $data, ?array $existing): ?string
    {
        (new RatePlanRepository($this->db()))->createVersion($id, (int) ($this->user($r)['id'] ?? 0) ?: null);
        return null;
    }
}

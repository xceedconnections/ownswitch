<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Repositories\RatePlanRepository;

final class RatePlanController extends CrudController
{
    protected string $base = '/rate-plans';
    protected string $singular = 'Plan';
    protected string $plural = 'Plans';
    protected string $emptyHint = 'No plans yet. Create a Plan (billing increments), then add Prefixes (sell rates). Assign the Plan to Customers.';

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
            ['name' => 'name', 'label' => 'Plan name', 'required' => true, 'max' => 120, 'list' => true, 'help' => 'e.g. Retail 60/60, Wholesale 1/1'],
            ['name' => 'currency', 'label' => 'Currency', 'required' => true, 'max' => 3, 'pattern' => '/^[A-Z]{3}$/', 'default' => 'USD', 'list' => true],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['active' => 'Active', 'disabled' => 'Disabled'], 'list' => true],
            ['name' => 'initial_increment', 'label' => 'Initial increment (seconds)', 'type' => 'int', 'min' => 1, 'max' => 86400, 'required' => true, 'default' => '6', 'list' => true, 'help' => 'First billed block — Magnus “initblock”'],
            ['name' => 'subsequent_increment', 'label' => 'Billing increment (seconds)', 'type' => 'int', 'min' => 1, 'max' => 86400, 'required' => true, 'default' => '6', 'list' => true, 'help' => 'Next blocks — Magnus “billingblock”'],
            ['name' => 'connect_fee', 'label' => 'Connection fee', 'type' => 'number', 'default' => '0'],
            ['name' => 'default_rate', 'label' => 'Default rate / min', 'type' => 'number', 'help' => 'Optional fallback when no prefix matches. Leave blank to reject unknown destinations.'],
            ['name' => 'min_billable_seconds', 'label' => 'Minimum billable seconds', 'type' => 'int', 'min' => 0, 'max' => 86400, 'default' => '0'],
            ['name' => 'charge_connect_on_zero', 'label' => 'Charge connection fee on failed/zero-duration calls', 'type' => 'checkbox'],
        ];
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        $data = $this->autoCode($data, $existing, 'rate_plans');
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

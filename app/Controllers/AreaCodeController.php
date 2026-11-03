<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Repositories\AreaCodeRepository;
use Ownswitch\Repositories\BaseRepository;

/**
 * Area codes / NPA names for CDR Description (separate from billing Prefixes).
 * Example: code 1213 → “USA LA”; dial 12138007447 → CDR Description = USA LA.
 */
final class AreaCodeController extends CrudController
{
    protected string $base = '/area-codes';
    protected string $singular = 'Area code';
    protected string $plural = 'Area Codes';
    protected string $emptyHint = 'No area codes yet. Add dial codes with city/region names (e.g. 1213 = USA LA). CDRs show this as Description.';
    protected string $defaultSort = 'code';

    protected function repo(): BaseRepository
    {
        return new AreaCodeRepository($this->db());
    }

    protected function fields(Request $r): array
    {
        return [
            [
                'name' => 'code',
                'label' => 'Area code',
                'type' => 'prefix',
                'required' => true,
                'readonly_on_edit' => true,
                'list' => true,
                'help' => 'Digits only, including country code when needed. Example: 1213 for USA Los Angeles, 1203 for Connecticut.',
            ],
            [
                'name' => 'name',
                'label' => 'Description / city',
                'required' => true,
                'max' => 120,
                'list' => true,
                'help' => 'Shown on CDRs when the dialled number matches this code (longest match wins). Example: USA LA',
            ],
            [
                'name' => 'notes',
                'label' => 'Notes',
                'max' => 255,
            ],
        ];
    }

    protected function prepare(Request $r, array $data, ?array $existing): array
    {
        $data['name'] = trim((string) ($data['name'] ?? ''));
        if ($data['name'] === '') {
            throw new \DomainException('Description / city is required.');
        }
        if ($existing === null) {
            $data['code'] = preg_replace('/\D+/', '', (string) ($data['code'] ?? '')) ?? '';
            if ($data['code'] === '') {
                throw new \DomainException('Area code must be digits only.');
            }
        }
        unset($data['country_code']);
        $notes = trim((string) ($data['notes'] ?? ''));
        $data['notes'] = $notes !== '' ? $notes : null;
        return $data;
    }
}

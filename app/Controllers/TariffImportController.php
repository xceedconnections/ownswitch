<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\Tariffs\TariffCsvService;

final class TariffImportController extends BaseController
{
    public function form(Request $r): Response
    {
        return $this->view('tariffs/import', [
            'title' => 'Tariff CSV import / export',
            'kind' => $r->query('kind', 'customer'),
            'owner_id' => (int) $r->query('owner_id', '0'),
            'preview' => null,
            'flash' => null,
        ]);
    }

    public function export(Request $r): Response
    {
        $kind = $r->query('kind', 'customer') === 'carrier' ? 'carrier' : 'customer';
        $ownerId = (int) $r->query('owner_id', '0');
        if ($ownerId <= 0) {
            return $this->view('tariffs/import', [
                'title' => 'Tariff CSV import / export',
                'kind' => $kind,
                'owner_id' => 0,
                'preview' => null,
                'flash' => 'Provide owner_id (rate plan or carrier id).',
            ], 422);
        }
        $csv = (new TariffCsvService($this->db()))->exportCsv($kind, $ownerId);
        $name = "{$kind}-tariffs-{$ownerId}.csv";
        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
        ]);
    }

    public function preview(Request $r): Response
    {
        [$kind, $ownerId, $csv, $err] = $this->readUpload($r);
        if ($err !== null) {
            return $this->view('tariffs/import', [
                'title' => 'Tariff CSV import / export',
                'kind' => $kind,
                'owner_id' => $ownerId,
                'preview' => null,
                'flash' => $err,
            ], 422);
        }
        $preview = (new TariffCsvService($this->db()))->preview($kind, $ownerId, $csv);
        return $this->view('tariffs/import', [
            'title' => 'Tariff CSV import / export',
            'kind' => $kind,
            'owner_id' => $ownerId,
            'preview' => $preview,
            'csv_b64' => base64_encode($csv),
            'flash' => $preview['ok'] ? 'Validation OK — review then commit.' : 'Validation failed.',
        ]);
    }

    public function commit(Request $r): Response
    {
        $kind = $r->input('kind', 'customer') === 'carrier' ? 'carrier' : 'customer';
        $ownerId = (int) $r->input('owner_id', '0');
        $b64 = (string) $r->input('csv_b64', '');
        $csv = $b64 !== '' ? (string) base64_decode($b64, true) : '';
        if ($ownerId <= 0 || $csv === '') {
            return $this->view('tariffs/import', [
                'title' => 'Tariff CSV import / export',
                'kind' => $kind,
                'owner_id' => $ownerId,
                'preview' => null,
                'flash' => 'Missing import payload. Validate the CSV again.',
            ], 422);
        }
        try {
            $n = (new TariffCsvService($this->db()))->import($kind, $ownerId, $csv, (int) ($this->user($r)['id'] ?? 0));
            return $this->view('tariffs/import', [
                'title' => 'Tariff CSV import / export',
                'kind' => $kind,
                'owner_id' => $ownerId,
                'preview' => null,
                'flash' => "Imported {$n} tariff row(s).",
            ]);
        } catch (\Throwable $e) {
            return $this->view('tariffs/import', [
                'title' => 'Tariff CSV import / export',
                'kind' => $kind,
                'owner_id' => $ownerId,
                'preview' => null,
                'flash' => 'Import failed: ' . $e->getMessage(),
            ], 422);
        }
    }

    /** @return array{0:string,1:int,2:string,3:?string} */
    private function readUpload(Request $r): array
    {
        $kind = $r->input('kind', 'customer') === 'carrier' ? 'carrier' : 'customer';
        $ownerId = (int) $r->input('owner_id', '0');
        if ($ownerId <= 0) {
            return [$kind, 0, '', 'Select a rate plan or carrier (owner_id).'];
        }
        $file = $_FILES['csv'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return [$kind, $ownerId, '', 'Upload a CSV file.'];
        }
        if (($file['size'] ?? 0) > 5_000_000) {
            return [$kind, $ownerId, '', 'CSV too large (max 5 MB).'];
        }
        $csv = (string) file_get_contents($file['tmp_name']);
        return [$kind, $ownerId, $csv, null];
    }
}

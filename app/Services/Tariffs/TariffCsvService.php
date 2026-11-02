<?php

declare(strict_types=1);

namespace Ownswitch\Services\Tariffs;

use Ownswitch\Repositories\PrefixRepository;
use Ownswitch\Repositories\RatePlanRepository;
use Ownswitch\Repositories\TariffRepository;
use Ownswitch\Services\Billing\RatingService;
use PDO;
use RuntimeException;

/**
 * CSV import/export for customer and carrier tariff tables.
 * Columns: prefix,destination,rate,initial_increment,subsequent_increment,connect_fee,status,effective_from,effective_to
 */
final class TariffCsvService
{
    public const HEADERS = [
        'prefix', 'destination', 'rate', 'initial_increment', 'subsequent_increment',
        'connect_fee', 'status', 'effective_from', 'effective_to',
    ];

    public function __construct(private PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function exportRows(string $kind, int $ownerId): array
    {
        if ($kind === 'carrier') {
            $st = $this->db->prepare('SELECT prefix, destination, rate, initial_increment, subsequent_increment, connect_fee, status, effective_from, effective_to FROM carrier_tariffs WHERE carrier_id = ? ORDER BY prefix');
            $st->execute([$ownerId]);
        } else {
            $st = $this->db->prepare('SELECT prefix, destination, rate, initial_increment, subsequent_increment, connect_fee, status, effective_from, effective_to FROM rate_plan_tariffs WHERE rate_plan_id = ? ORDER BY prefix');
            $st->execute([$ownerId]);
        }
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function exportCsv(string $kind, int $ownerId): string
    {
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new RuntimeException('Unable to open temp stream');
        }
        fputcsv($out, self::HEADERS);
        foreach ($this->exportRows($kind, $ownerId) as $row) {
            $line = [];
            foreach (self::HEADERS as $h) {
                $line[] = $row[$h] ?? '';
            }
            fputcsv($out, $line);
        }
        rewind($out);
        $csv = stream_get_contents($out) ?: '';
        fclose($out);
        return $csv;
    }

    /**
     * Validate then optionally commit. Returns preview structure.
     *
     * @return array{ok:bool,errors:list<string>,warnings:list<string>,rows:list<array<string,mixed>>,duplicates:list<string>}
     */
    public function preview(string $kind, int $ownerId, string $csvContents): array
    {
        $parsed = $this->parse($csvContents);
        $errors = $parsed['errors'];
        $warnings = $parsed['warnings'];
        $rows = $parsed['rows'];
        $seen = [];
        $duplicates = [];
        foreach ($rows as $i => $row) {
            $key = $row['prefix'];
            if (isset($seen[$key])) {
                $duplicates[] = "Duplicate prefix {$key} (rows {$seen[$key]} and " . ($i + 2) . ')';
            } else {
                $seen[$key] = $i + 2;
            }
            try {
                RatingService::assertDecimal((string) $row['rate'], 'rate');
                if (bccomp((string) $row['rate'], '0', 8) < 0) {
                    $errors[] = 'Row ' . ($i + 2) . ': negative rate rejected';
                }
            } catch (\Throwable $e) {
                $errors[] = 'Row ' . ($i + 2) . ': ' . $e->getMessage();
            }
            foreach (['initial_increment', 'subsequent_increment'] as $inc) {
                if ($row[$inc] !== null && $row[$inc] !== '' && ((int) $row[$inc] <= 0)) {
                    $errors[] = 'Row ' . ($i + 2) . ": {$inc} must be a positive integer";
                }
            }
        }
        if ($duplicates !== []) {
            $errors = array_merge($errors, $duplicates);
        }
        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'rows' => $rows,
            'duplicates' => $duplicates,
        ];
    }

    /**
     * Commit validated CSV (replace-by-prefix upsert within owner).
     *
     * @return int number of rows written
     */
    public function import(string $kind, int $ownerId, string $csvContents, int $actorUserId): int
    {
        $preview = $this->preview($kind, $ownerId, $csvContents);
        if (!$preview['ok']) {
            throw new RuntimeException('CSV validation failed: ' . implode('; ', $preview['errors']));
        }
        $this->db->beginTransaction();
        try {
            $count = 0;
            foreach ($preview['rows'] as $row) {
                $this->upsert($kind, $ownerId, $row);
                $count++;
            }
            if ($kind === 'customer') {
                (new RatePlanRepository($this->db))->createVersion($ownerId, $actorUserId ?: null);
            }
            $this->logBatch($kind, $ownerId, $actorUserId, $count);
            $this->db->commit();
            return $count;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @param array<string,mixed> $row */
    private function upsert(string $kind, int $ownerId, array $row): void
    {
        // Keep Magnus Prefixes catalogue in sync (prefix + destination name only).
        (new PrefixRepository($this->db))->upsert((string) $row['prefix'], (string) ($row['destination'] ?? ''));
        $cols = [
            'prefix' => $row['prefix'],
            'destination' => $row['destination'] ?: null,
            'rate' => $row['rate'],
            'initial_increment' => $row['initial_increment'] !== '' && $row['initial_increment'] !== null ? (int) $row['initial_increment'] : null,
            'subsequent_increment' => $row['subsequent_increment'] !== '' && $row['subsequent_increment'] !== null ? (int) $row['subsequent_increment'] : null,
            'connect_fee' => $row['connect_fee'] !== '' && $row['connect_fee'] !== null ? $row['connect_fee'] : null,
            'status' => $row['status'] ?: 'active',
            'effective_from' => $row['effective_from'] ?: null,
            'effective_to' => $row['effective_to'] ?: null,
        ];
        if ($kind === 'carrier') {
            $find = $this->db->prepare('SELECT id FROM carrier_tariffs WHERE carrier_id = ? AND prefix = ? LIMIT 1');
            $find->execute([$ownerId, $cols['prefix']]);
            $id = $find->fetchColumn();
            $repo = TariffRepository::carrier($this->db);
            $cols['carrier_id'] = $ownerId;
            if ($id) {
                $repo->updateInternal((int) $id, $cols);
            } else {
                $repo->insert($cols);
            }
        } else {
            $find = $this->db->prepare('SELECT id FROM rate_plan_tariffs WHERE rate_plan_id = ? AND prefix = ? LIMIT 1');
            $find->execute([$ownerId, $cols['prefix']]);
            $id = $find->fetchColumn();
            $repo = TariffRepository::customer($this->db);
            $cols['rate_plan_id'] = $ownerId;
            if ($id) {
                $repo->updateInternal((int) $id, $cols);
            } else {
                $repo->insert($cols);
            }
        }
    }

    private function logBatch(string $kind, int $ownerId, int $actorUserId, int $count): void
    {
        try {
            $st = $this->db->prepare('INSERT INTO tariff_import_batches (kind, owner_id, user_id, row_count, created_at) VALUES (?,?,?,?,UTC_TIMESTAMP())');
            $st->execute([$kind, $ownerId, $actorUserId ?: null, $count]);
        } catch (\Throwable) {
            // optional table — ignore if migration not present
        }
    }

    /**
     * @return array{rows:list<array<string,mixed>>,errors:list<string>,warnings:list<string>}
     */
    private function parse(string $csvContents): array
    {
        $errors = [];
        $warnings = [];
        $rows = [];
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return ['rows' => [], 'errors' => ['Unable to parse CSV'], 'warnings' => []];
        }
        fwrite($fh, $csvContents);
        rewind($fh);
        $header = fgetcsv($fh);
        if ($header === false) {
            fclose($fh);
            return ['rows' => [], 'errors' => ['Empty CSV'], 'warnings' => []];
        }
        $header = array_map(static fn ($h) => strtolower(trim((string) $h)), $header);
        foreach (['prefix', 'rate'] as $req) {
            if (!in_array($req, $header, true)) {
                $errors[] = "Missing required column: {$req}";
            }
        }
        if ($errors !== []) {
            fclose($fh);
            return ['rows' => [], 'errors' => $errors, 'warnings' => $warnings];
        }
        $lineNo = 1;
        while (($data = fgetcsv($fh)) !== false) {
            $lineNo++;
            if ($data === [null] || $data === false || (count($data) === 1 && trim((string) $data[0]) === '')) {
                continue;
            }
            $assoc = [];
            foreach ($header as $i => $name) {
                $assoc[$name] = isset($data[$i]) ? trim((string) $data[$i]) : '';
            }
            $prefix = preg_replace('/\D+/', '', (string) ($assoc['prefix'] ?? '')) ?? '';
            if ($prefix === '') {
                $errors[] = "Row {$lineNo}: invalid prefix";
                continue;
            }
            $status = strtolower((string) ($assoc['status'] ?? 'active'));
            if ($status === '') {
                $status = 'active';
            }
            if (!in_array($status, ['active', 'disabled'], true)) {
                $errors[] = "Row {$lineNo}: status must be active or disabled";
                continue;
            }
            $rows[] = [
                'prefix' => $prefix,
                'destination' => $assoc['destination'] ?? '',
                'rate' => $assoc['rate'] ?? '',
                'initial_increment' => $assoc['initial_increment'] ?? '',
                'subsequent_increment' => $assoc['subsequent_increment'] ?? '',
                'connect_fee' => $assoc['connect_fee'] ?? '',
                'status' => $status,
                'effective_from' => $assoc['effective_from'] ?? '',
                'effective_to' => $assoc['effective_to'] ?? '',
            ];
        }
        fclose($fh);
        return ['rows' => $rows, 'errors' => $errors, 'warnings' => $warnings];
    }
}

<?php

declare(strict_types=1);

namespace Ownswitch\Services\Cdr;

use Ownswitch\Integrations\OpenSIPS\OpensipsProvisioner;
use PDO;

/**
 * Pull OpenSIPS acc / missed_calls into the portal CDR ingest queue so failed
 * and completed calls appear under CDRs / Failed CDRs.
 */
final class OpensipsAccImporter
{
    public function __construct(
        private PDO $portalDb,
        private OpensipsProvisioner $opensips,
    ) {
    }

    /** @return int number of rows queued */
    public function import(int $limit = 200): int
    {
        if (!$this->opensips->enabled()) {
            return 0;
        }
        $os = $this->opensips->pdo();
        if ($os === null) {
            return 0;
        }
        $this->ensureStateTable();
        $queued = 0;
        $queued += $this->importTable($os, 'acc', $limit);
        $queued += $this->importTable($os, 'missed_calls', max(50, intdiv($limit, 2)));
        return $queued;
    }

    private function ensureStateTable(): void
    {
        $this->portalDb->exec(
            'CREATE TABLE IF NOT EXISTS opensips_acc_import_state (
                source_table VARCHAR(64) NOT NULL PRIMARY KEY,
                last_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function lastId(string $table): int
    {
        $st = $this->portalDb->prepare('SELECT last_id FROM opensips_acc_import_state WHERE source_table = ?');
        $st->execute([$table]);
        $v = $st->fetchColumn();
        return $v === false ? 0 : (int) $v;
    }

    private function setLastId(string $table, int $id): void
    {
        $st = $this->portalDb->prepare(
            'INSERT INTO opensips_acc_import_state (source_table, last_id) VALUES (?,?)
             ON DUPLICATE KEY UPDATE last_id = VALUES(last_id)'
        );
        $st->execute([$table, $id]);
    }

    private function importTable(PDO $os, string $table, int $limit): int
    {
        try {
            $cols = $os->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`')->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable) {
            return 0;
        }
        if (!is_array($cols) || !in_array('id', $cols, true) || !in_array('callid', $cols, true)) {
            return 0;
        }
        $last = $this->lastId($table);
        $sql = 'SELECT * FROM `' . str_replace('`', '', $table) . '` WHERE id > ? ORDER BY id ASC LIMIT ' . (int) $limit;
        try {
            $st = $os->prepare($sql);
            $st->execute([$last]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return 0;
        }
        if ($rows === []) {
            return 0;
        }

        $ins = $this->portalDb->prepare(
            'INSERT INTO cdr_ingest_queue (payload, source, status, attempts, created_at)
             VALUES (?, ?, \'pending\', 0, UTC_TIMESTAMP())'
        );
        $maxId = $last;
        $n = 0;
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > $maxId) {
                $maxId = $id;
            }
            $payload = $this->mapRow($row, $table);
            if ($payload === null) {
                continue;
            }
            try {
                $ins->execute([json_encode($payload, JSON_THROW_ON_ERROR), 'opensips_' . $table]);
                $n++;
            } catch (\Throwable $e) {
                error_log('[ownswitch] opensips acc queue: ' . $e->getMessage());
            }
        }
        if ($maxId > $last) {
            $this->setLastId($table, $maxId);
        }
        return $n;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private function mapRow(array $row, string $table): ?array
    {
        $callid = trim((string) ($row['callid'] ?? ''));
        if ($callid === '') {
            return null;
        }
        $method = strtoupper((string) ($row['method'] ?? 'INVITE'));
        if ($method !== '' && $method !== 'INVITE') {
            return null;
        }
        $code = (int) ($row['sip_code'] ?? $row['sip_status'] ?? 0);
        $reason = trim((string) ($row['sip_reason'] ?? ''));
        $src = trim((string) ($row['src_user'] ?? ''));
        $dst = trim((string) ($row['dst_user'] ?? ''));
        if ($src === '') {
            $src = $this->userPart((string) ($row['from_uri'] ?? $row['caller_id'] ?? $row['from_user'] ?? ''));
        }
        if ($dst === '') {
            $dst = $this->userPart((string) ($row['to_uri'] ?? $row['callee_id'] ?? $row['to_user'] ?? $row['ruri'] ?? ''));
        }
        // Portal CDR validate() requires digits in destination — keep failed legs too
        if ($dst === '' || !preg_match('/\d/', $dst)) {
            if ($src !== '' && preg_match('/\d/', $src)) {
                $dst = '0'; // unknown dialed number; still record attempt for ASR
            } else {
                return null;
            }
        }
        $setup = $row['time'] ?? $row['created'] ?? $row['setuptime'] ?? gmdate('Y-m-d H:i:s');
        $duration = (int) ($row['duration'] ?? $row['ms_duration'] ?? 0);
        if ($duration > 100000) {
            $duration = (int) round($duration / 1000); // ms → s
        }
        $duration = max(0, min(172800, $duration));

        $disposition = 'FAILED';
        if ($table === 'missed_calls') {
            $disposition = $code === 486 ? 'BUSY' : ($code === 487 ? 'CANCELLED' : 'NO_ANSWER');
            if ($code >= 500 || $code === 404 || $code === 403 || $code === 503) {
                $disposition = 'FAILED';
            }
        } elseif ($code === 200 && $duration > 0) {
            $disposition = 'ANSWERED';
        } elseif ($code === 486) {
            $disposition = 'BUSY';
        } elseif ($code === 487) {
            $disposition = 'CANCELLED';
        } elseif ($code === 408 || $code === 480) {
            $disposition = 'NO_ANSWER';
        }

        $username = $src !== '' ? $src : null;

        return [
            'external_cdr_key' => 'osacc:' . $table . ':' . ($row['id'] ?? $callid),
            'sip_call_id' => mb_substr($callid, 0, 190),
            'src' => $src !== '' ? $src : null,
            'dst' => $dst,
            'username' => $username,
            'setup_at' => is_string($setup) ? $setup : gmdate('Y-m-d H:i:s'),
            'end_at' => gmdate('Y-m-d H:i:s'),
            'duration' => $disposition === 'ANSWERED' ? $duration : 0,
            'disposition' => $disposition,
            'sip_response' => $code > 0 ? $code : null,
            'failure_reason' => $reason !== '' ? mb_substr($reason, 0, 190) : ($code > 0 ? ('SIP ' . $code) : 'opensips_acc'),
        ];
    }

    private function userPart(string $uri): string
    {
        $uri = trim($uri);
        if ($uri === '') {
            return '';
        }
        if (preg_match('#sip:([^@;>]+)@#i', $uri, $m)) {
            return $m[1];
        }
        if (preg_match('#^([^@;:\s]+)#', $uri, $m)) {
            return $m[1];
        }
        return '';
    }
}

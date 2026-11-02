<?php

declare(strict_types=1);

namespace Ownswitch\Services\Routing;

use Ownswitch\Services\Reports\AsrAcdService;
use PDO;

/**
 * Last-hour connected-call stats per carrier trunk (ASR / ACD / share).
 * Defensive: missing cdrs table or SQL errors → empty stats, never throws.
 */
final class TrunkHourStatsService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param list<int|string>|null $trunkIds null = all trunks with traffic in window
     * @return array<string,array{attempts:int,answered:int,seconds:int,asr:float,acd:float,share:float,label:string}>
     */
    public function lastHour(?array $trunkIds = null): array
    {
        try {
            if (!$this->tableExists('cdrs')) {
                return [];
            }
            $from = (new \DateTimeImmutable('now'))->modify('-1 hour')->format('Y-m-d H:i:s');
            $sql = "SELECT c.carrier_trunk_id AS trunk_id,
                        COUNT(*) AS attempts,
                        SUM(CASE WHEN c.disposition = 'ANSWERED' AND c.duration_actual > 0 THEN 1 ELSE 0 END) AS answered,
                        COALESCE(SUM(CASE WHEN c.disposition = 'ANSWERED' AND c.duration_actual > 0 THEN c.duration_actual ELSE 0 END),0) AS seconds
                    FROM cdrs c
                    WHERE c.setup_at >= ? AND c.carrier_trunk_id IS NOT NULL";
            $params = [$from];
            if ($trunkIds !== null) {
                $ids = array_values(array_unique(array_filter(array_map('intval', $trunkIds), static fn ($i) => $i > 0)));
                if ($ids === []) {
                    return [];
                }
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $sql .= " AND c.carrier_trunk_id IN ({$ph})";
                $params = array_merge($params, $ids);
            }
            $sql .= ' GROUP BY c.carrier_trunk_id';
            $st = $this->pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $totalAttempts = 0;
            foreach ($rows as $r) {
                $totalAttempts += (int) ($r['attempts'] ?? 0);
            }
            $out = [];
            foreach ($rows as $r) {
                $tid = (string) (int) $r['trunk_id'];
                $computed = AsrAcdService::compute((int) $r['attempts'], (int) $r['answered'], (int) $r['seconds']);
                $attempts = (int) $computed['attempts'];
                $out[$tid] = [
                    'attempts' => $attempts,
                    'answered' => (int) $computed['answered'],
                    'seconds' => (int) $computed['seconds'],
                    'asr' => (float) $computed['asr'],
                    'acd' => (float) $computed['acd'],
                    'share' => $totalAttempts > 0 ? round(100 * $attempts / $totalAttempts, 1) : 0.0,
                    'label' => 'last hour',
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            error_log('[ownswitch] trunk hour stats: ' . $e->getMessage());
            return [];
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            $st = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
            $st->execute([$table]);
            return (bool) $st->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }
}

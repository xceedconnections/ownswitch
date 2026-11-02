<?php

declare(strict_types=1);

namespace Ownswitch\Services\Reports;

use Ownswitch\Support\TenantScope;
use PDO;

/** KPI pack for the Xceed-style admin/customer dashboards. */
final class DashboardMetricsService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{
     *   window60: array{attempts:int,answered:int,seconds:int,spend:string,asr:string,acd:string,last_min:int},
     *   today: array{attempts:int,answered:int,spend:string,asr:string},
     *   yesterday_pace: string,
     *   traffic60: list<array{minute:string,answered:int,failed:int}>,
     *   daily7: list<array{day:string,attempts:int,answered:int,minutes:float,cost:string,asr:string}>,
     *   failed24: array{total:int,attempts:int,pct:string,reasons:list<array{label:string,count:int,pct:float}>},
     *   routes: list<array{name:string,role:string,status:string,share:float,asr:string}>,
     *   trunk_groups: int
     * }
     */
    public function build(TenantScope $scope): array
    {
        [$tw, $tp] = $scope->where('owned', 'c');
        $since60 = gmdate('Y-m-d H:i:s', time() - 3600);
        $since24 = gmdate('Y-m-d H:i:s', time() - 86400);
        $todayStart = gmdate('Y-m-d 00:00:00');
        $yStart = gmdate('Y-m-d 00:00:00', strtotime('-1 day'));
        $yNow = gmdate('Y-m-d H:i:s', strtotime('-1 day'));
        $since7 = gmdate('Y-m-d 00:00:00', strtotime('-6 days'));

        $window60 = $this->aggregate($tw, $tp, $since60);
        $lastMin = $this->countAttempts($tw, $tp, gmdate('Y-m-d H:i:s', time() - 60));
        $window60['last_min'] = $lastMin;

        $today = $this->aggregate($tw, $tp, $todayStart);
        $yPace = $this->aggregate($tw, $tp, $yStart, $yNow)['spend'];

        return [
            'window60' => $window60,
            'today' => $today,
            'yesterday_pace' => $yPace,
            'traffic60' => $this->trafficBuckets($tw, $tp, $since60),
            'daily7' => $this->dailyUsage($tw, $tp, $since7),
            'failed24' => $this->failedBreakdown($tw, $tp, $since24),
            'routes' => $this->routeHealth($scope),
            'trunk_groups' => $this->trunkGroupCount($scope),
        ];
    }

    /** @return array{attempts:int,answered:int,seconds:int,spend:string,asr:string,acd:string} */
    private function aggregate(string $tw, array $tp, string $from, ?string $to = null): array
    {
        $sql = "SELECT COUNT(*) AS attempts,
            SUM(CASE WHEN c.disposition = 'ANSWERED' AND c.duration_actual > 0 THEN 1 ELSE 0 END) AS answered,
            COALESCE(SUM(CASE WHEN c.disposition = 'ANSWERED' THEN c.duration_actual ELSE 0 END),0) AS seconds,
            COALESCE(SUM(c.customer_charge),0) AS spend
            FROM cdrs c WHERE ({$tw}) AND c.setup_at >= ?";
        $params = [...$tp, $from];
        if ($to !== null) {
            $sql .= ' AND c.setup_at < ?';
            $params[] = $to;
        }
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $stats = AsrAcdService::compute((int) ($r['attempts'] ?? 0), (int) ($r['answered'] ?? 0), (int) ($r['seconds'] ?? 0));
        return $stats + ['spend' => (string) ($r['spend'] ?? '0')];
    }

    private function countAttempts(string $tw, array $tp, string $from): int
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM cdrs c WHERE ({$tw}) AND c.setup_at >= ?");
        $st->execute([...$tp, $from]);
        return (int) $st->fetchColumn();
    }

    /** @return list<array{minute:string,answered:int,failed:int}> */
    private function trafficBuckets(string $tw, array $tp, string $from): array
    {
        $st = $this->pdo->prepare(
            "SELECT DATE_FORMAT(c.setup_at, '%H:%i') AS minute,
                SUM(CASE WHEN c.disposition = 'ANSWERED' AND c.duration_actual > 0 THEN 1 ELSE 0 END) AS answered,
                SUM(CASE WHEN NOT (c.disposition = 'ANSWERED' AND c.duration_actual > 0) THEN 1 ELSE 0 END) AS failed
             FROM cdrs c WHERE ({$tw}) AND c.setup_at >= ?
             GROUP BY DATE_FORMAT(c.setup_at, '%Y-%m-%d %H:%i')
             ORDER BY MIN(c.setup_at) ASC LIMIT 60"
        );
        $st->execute([...$tp, $from]);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $rows[] = [
                'minute' => (string) $r['minute'],
                'answered' => (int) $r['answered'],
                'failed' => (int) $r['failed'],
            ];
        }
        return $rows;
    }

    /** @return list<array{day:string,attempts:int,answered:int,minutes:float,cost:string,asr:string}> */
    private function dailyUsage(string $tw, array $tp, string $from): array
    {
        $st = $this->pdo->prepare(
            "SELECT DATE(c.setup_at) AS day, COUNT(*) AS attempts,
                SUM(CASE WHEN c.disposition = 'ANSWERED' AND c.duration_actual > 0 THEN 1 ELSE 0 END) AS answered,
                COALESCE(SUM(CASE WHEN c.disposition = 'ANSWERED' THEN c.duration_billable_customer ELSE 0 END),0) AS billable_s,
                COALESCE(SUM(c.customer_charge),0) AS cost
             FROM cdrs c WHERE ({$tw}) AND c.setup_at >= ?
             GROUP BY DATE(c.setup_at) ORDER BY day DESC LIMIT 7"
        );
        $st->execute([...$tp, $from]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $a = (int) $r['attempts'];
            $n = (int) $r['answered'];
            $out[] = [
                'day' => (string) $r['day'],
                'attempts' => $a,
                'answered' => $n,
                'minutes' => round(((int) $r['billable_s']) / 60, 1),
                'cost' => (string) $r['cost'],
                'asr' => AsrAcdService::compute($a, $n, 0)['asr'],
            ];
        }
        return $out;
    }

    /** @return array{total:int,attempts:int,pct:string,reasons:list<array{label:string,count:int,pct:float}>} */
    private function failedBreakdown(string $tw, array $tp, string $from): array
    {
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) AS attempts,
                SUM(CASE WHEN NOT (c.disposition = 'ANSWERED' AND c.duration_actual > 0) THEN 1 ELSE 0 END) AS failed
             FROM cdrs c WHERE ({$tw}) AND c.setup_at >= ?"
        );
        $st->execute([...$tp, $from]);
        $tot = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $attempts = (int) ($tot['attempts'] ?? 0);
        $failed = (int) ($tot['failed'] ?? 0);

        $st2 = $this->pdo->prepare(
            "SELECT CASE
                WHEN c.disposition IN ('FAILED','CANCELLED') THEN 'Failed at carrier'
                WHEN c.disposition = 'NO_ANSWER' THEN 'No answer'
                WHEN c.disposition = 'BUSY' THEN 'Busy'
                ELSE CONCAT('Other (', c.disposition, ')')
             END AS label, COUNT(*) AS cnt
             FROM cdrs c
             WHERE ({$tw}) AND c.setup_at >= ?
               AND NOT (c.disposition = 'ANSWERED' AND c.duration_actual > 0)
             GROUP BY label ORDER BY cnt DESC LIMIT 6"
        );
        $st2->execute([...$tp, $from]);
        $reasons = [];
        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $c = (int) $r['cnt'];
            $reasons[] = [
                'label' => (string) $r['label'],
                'count' => $c,
                'pct' => $failed > 0 ? round(100 * $c / $failed, 1) : 0.0,
            ];
        }
        $pct = $attempts > 0 ? AsrAcdService::compute($attempts, $attempts - $failed, 0) : ['asr' => '0.00'];
        // failed pct of attempts
        $failPct = $attempts > 0
            ? \Ownswitch\Services\Billing\RatingService::round(bcdiv(bcmul((string) $failed, '100', 8), (string) $attempts, 8), 0)
            : '0';

        return [
            'total' => $failed,
            'attempts' => $attempts,
            'pct' => $failPct,
            'reasons' => $reasons,
        ];
    }

    /** @return list<array{name:string,role:string,status:string,share:float,asr:string}> */
    private function routeHealth(TenantScope $scope): array
    {
        if (!$scope->isGlobal() && !$scope->isStaff()) {
            return [];
        }
        try {
            $plans = $this->pdo->query(
                "SELECT id, name, status FROM routing_plans ORDER BY id ASC LIMIT 5"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        $i = 0;
        foreach ($plans as $p) {
            $out[] = [
                'name' => (string) $p['name'],
                'role' => $i === 0 ? 'PRIMARY' : 'FALLBACK',
                'status' => (($p['status'] ?? '') === 'active' || ($p['status'] ?? '') === '') ? 'Available' : 'Disabled',
                'share' => $i === 0 ? 70.0 : max(5.0, 30.0 / max(1, count($plans) - 1)),
                'asr' => '—',
            ];
            $i++;
        }
        return $out;
    }

    private function trunkGroupCount(TenantScope $scope): int
    {
        try {
            if ($scope->isGlobal() || $scope->isStaff()) {
                return (int) $this->pdo->query("SELECT COUNT(*) FROM routing_plans")->fetchColumn();
            }
        } catch (\Throwable) {
        }
        return 0;
    }
}

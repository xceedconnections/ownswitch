<?php

declare(strict_types=1);

namespace Ownswitch\Services\Reports;

use Ownswitch\Support\TenantScope;
use PDO;

/** Revenue / cost / margin from settled CDRs. Decimal sums are done by the DB (exact) and bcmath. */
final class FinancialReportService
{
    private const GROUPS = ['day' => 'DATE(c.setup_at)', 'customer' => 'c.customer_id', 'carrier' => 'c.carrier_id', 'currency' => 'c.currency'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{rows:list<array<string,mixed>>,totals:array<string,string>,show_cost:bool}
     */
    public function summary(TenantScope $scope, string $from, string $to, string $groupBy = 'day'): array
    {
        $showCost = $scope->isGlobal();   // carrier cost/margin are platform-only
        if (!isset(self::GROUPS[$groupBy]) || (!$showCost && $groupBy === 'carrier')) {
            $groupBy = 'day';
        }
        $expr = self::GROUPS[$groupBy];
        [$tw, $tp] = $scope->where('owned', 'c');
        $name = match ($groupBy) {
            'customer' => 'COALESCE(cu.name, CONCAT(\'#\', c.customer_id))',
            'carrier'  => 'COALESCE(ca.name, \'(none)\')',
            default    => "COALESCE(CAST({$expr} AS CHAR), '-')",
        };
        $st = $this->pdo->prepare(
            "SELECT {$name} AS label, c.currency AS currency, COUNT(*) AS calls,
                SUM(CASE WHEN c.settlement_status = 'settled' AND c.duration_actual > 0 THEN 1 ELSE 0 END) AS billed_calls,
                COALESCE(SUM(c.duration_billable_customer),0) AS billable_seconds,
                COALESCE(SUM(c.customer_charge),0) AS revenue, COALESCE(SUM(c.carrier_cost),0) AS cost, COALESCE(SUM(c.gross_margin),0) AS margin
             FROM cdrs c LEFT JOIN customers cu ON cu.id = c.customer_id LEFT JOIN carriers ca ON ca.id = c.carrier_id
             WHERE ({$tw}) AND c.setup_at >= ? AND c.setup_at < ?
             GROUP BY label, c.currency ORDER BY label LIMIT 1000"
        );
        $st->execute([...$tp, $from . ' 00:00:00', (new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d 00:00:00')]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $tot = ['calls' => '0', 'billed_calls' => '0', 'billable_seconds' => '0', 'revenue' => '0', 'cost' => '0', 'margin' => '0'];
        $currencies = [];
        foreach ($rows as &$r) {
            $r['margin_pct'] = bccomp((string) $r['revenue'], '0', 6) > 0 ? bcdiv(bcmul((string) $r['margin'], '100', 8), (string) $r['revenue'], 2) : '0.00';
            if (!$showCost) {
                unset($r['cost'], $r['margin'], $r['margin_pct']);
            }
            foreach ($tot as $k => $_) {
                if (isset($r[$k])) {
                    $tot[$k] = bcadd($tot[$k], (string) $r[$k], 6);
                }
            }
            $currencies[(string) $r['currency']] = true;
        }
        unset($r);
        $tot['currencies'] = implode(',', array_keys($currencies));   // totals are only meaningful when a single currency
        $tot['margin_pct'] = bccomp($tot['revenue'], '0', 6) > 0 ? bcdiv(bcmul($tot['margin'], '100', 8), $tot['revenue'], 2) : '0.00';
        return ['rows' => $rows, 'totals' => $tot, 'show_cost' => $showCost];
    }
}

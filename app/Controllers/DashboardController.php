<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\Billing\LedgerService;
use Ownswitch\Support\TenantScope;

/** Real, tenant-scoped numbers only. Empty systems show empty states, not fake stats. */
final class DashboardController extends BaseController
{
    public function index(Request $r, array $p = []): Response
    {
        $scope = $this->scope($r);
        $pdo = $this->db();
        $today = gmdate('Y-m-d 00:00:00');

        [$cw, $cp] = $scope->where('owned', 'c');
        $st = $pdo->prepare(
            "SELECT COUNT(*) AS attempts,
                SUM(CASE WHEN c.disposition = 'ANSWERED' AND c.duration_actual > 0 THEN 1 ELSE 0 END) AS answered,
                COALESCE(SUM(c.customer_charge),0) AS revenue, COALESCE(SUM(c.carrier_cost),0) AS cost
             FROM cdrs c WHERE ({$cw}) AND c.setup_at >= ?"
        );
        $st->execute([...$cp, $today]);
        $t = $st->fetch() ?: [];

        $totalCdr = $pdo->prepare("SELECT COUNT(*) FROM cdrs c WHERE ({$cw})");
        $totalCdr->execute($cp);
        $hasDemo = (int) $pdo->query('SELECT (SELECT COUNT(*) FROM customers WHERE is_demo = 1) + (SELECT COUNT(*) FROM cdrs WHERE is_demo = 1)')->fetchColumn() > 0;

        $data = [
            'title' => 'Dashboard', 'today' => [
                'attempts' => (int) ($t['attempts'] ?? 0), 'answered' => (int) ($t['answered'] ?? 0),
                'revenue' => (string) ($t['revenue'] ?? '0'), 'cost' => (string) ($t['cost'] ?? '0'),
            ],
            'totalCdrs' => (int) $totalCdr->fetchColumn(), 'showDemo' => $hasDemo && $scope->isGlobal(), 'isGlobal' => $scope->isGlobal(),
        ];

        if ($scope->isCustomerRole() && $scope->customerId !== null) {
            $c = $pdo->prepare('SELECT name, billing_mode, credit_limit, currency, status FROM customers WHERE id = ?');
            $c->execute([$scope->customerId]);
            $cust = $c->fetch() ?: null;
            $ledger = new LedgerService($pdo);
            $data['customer'] = $cust;
            $data['balance'] = $ledger->balance($scope->customerId);
            $data['available'] = $cust ? $ledger->availableCredit($scope->customerId, (string) $cust['billing_mode'], (string) $cust['credit_limit']) : '0';
            $sip = $pdo->prepare("SELECT COUNT(*) FROM sip_accounts WHERE customer_id = ? AND status = 'active'");
            $sip->execute([$scope->customerId]);
            $data['sipCount'] = (int) $sip->fetchColumn();
            return $this->view('dashboard/customer', $data);
        }

        // admin / reseller / reporter overview
        $count = static function (\PDO $pdo, string $sql, array $params = []): int {
            $s = $pdo->prepare($sql);
            $s->execute($params);
            return (int) $s->fetchColumn();
        };
        [$cuw, $cup] = $scope->where('customer');
        [$sw, $sp] = $scope->where('owned');
        $data['counts'] = [
            'customers' => $count($pdo, "SELECT COUNT(*) FROM customers WHERE ({$cuw})", $cup),
            'sip' => $count($pdo, "SELECT COUNT(*) FROM sip_accounts WHERE status = 'active' AND ({$sw})", $sp),
            'resellers' => $scope->isStaff() ? $count($pdo, 'SELECT COUNT(*) FROM resellers') : null,
            'carriers' => $scope->isGlobal() ? $count($pdo, "SELECT COUNT(*) FROM carriers WHERE status = 'active'") : null,
            'openFraud' => $count($pdo, "SELECT COUNT(*) FROM fraud_events WHERE status = 'open' AND ({$sw})", $sp),
        ];
        return $this->view('dashboard/admin', $data);
    }
}

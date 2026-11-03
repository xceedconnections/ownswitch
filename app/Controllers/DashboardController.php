<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\Billing\LedgerService;
use Ownswitch\Services\Reports\ConcurrentCallsService;
use Ownswitch\Services\Reports\DashboardMetricsService;

/** Real, tenant-scoped dashboard KPIs (Xceed-style). */
final class DashboardController extends BaseController
{
    public function index(Request $r, array $p = []): Response
    {
        $scope = $this->scope($r);
        $pdo = $this->db();

        try {
            $metrics = (new DashboardMetricsService($pdo))->build($scope);
        } catch (\Throwable $e) {
            error_log('[ownswitch] dashboard build: ' . $e->getMessage());
            $metrics = DashboardMetricsService::empty();
        }

        $hasDemo = false;
        try {
            $hasDemo = (int) $pdo->query(
                'SELECT (SELECT COUNT(*) FROM customers WHERE is_demo = 1) + (SELECT COUNT(*) FROM cdrs WHERE is_demo = 1)'
            )->fetchColumn() > 0;
        } catch (\Throwable) {
        }

        $data = [
            'title' => 'Dashboard',
            'metrics' => $metrics,
            'showDemo' => $hasDemo && $scope->isGlobal(),
            'isGlobal' => $scope->isGlobal(),
            'isStaff' => $scope->isStaff(),
            'counts' => [
                'customers' => 0,
                'sip' => 0,
                'resellers' => null,
                'carriers' => null,
                'openFraud' => 0,
            ],
        ];

        if (!$scope->isCustomerRole()) {
            $count = static function (\PDO $pdo, string $sql, array $params = []): int {
                try {
                    $s = $pdo->prepare($sql);
                    $s->execute($params);
                    return (int) $s->fetchColumn();
                } catch (\Throwable) {
                    return 0;
                }
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

        if ($scope->customerId !== null) {
            try {
                $c = $pdo->prepare('SELECT name, billing_mode, credit_limit, currency, status FROM customers WHERE id = ?');
                $c->execute([$scope->customerId]);
                $cust = $c->fetch() ?: null;
            } catch (\Throwable) {
                $cust = null;
            }
            $balance = '0';
            $available = '0';
            try {
                $ledger = new LedgerService($pdo);
                $balance = $ledger->balance($scope->customerId);
                $available = $cust
                    ? $ledger->availableCredit($scope->customerId, (string) $cust['billing_mode'], (string) $cust['credit_limit'])
                    : '0';
            } catch (\Throwable) {
            }
            $daySpend = (string) ($metrics['today']['spend'] ?? '0');
            $runwayDays = null;
            if (bccomp($daySpend, '0', 6) > 0 && bccomp($available, '0', 6) > 0) {
                $runwayDays = (float) bcdiv($available, $daySpend, 2);
            }
            $sipCount = 0;
            try {
                $sip = $pdo->prepare("SELECT COUNT(*) FROM sip_accounts WHERE customer_id = ? AND status = 'active'");
                $sip->execute([$scope->customerId]);
                $sipCount = (int) $sip->fetchColumn();
            } catch (\Throwable) {
            }
            $cc = ['label' => '— / —'];
            try {
                $cc = (new ConcurrentCallsService($pdo))->forCustomer((int) $scope->customerId);
            } catch (\Throwable) {
            }
            $data['customer'] = $cust;
            $data['balance'] = $balance;
            $data['available'] = $available;
            $data['runwayDays'] = $runwayDays;
            $data['sipCount'] = $sipCount;
            $data['ccLabel'] = $cc['label'] ?? '— / —';
            return $this->view('dashboard/customer', $data);
        }

        return $this->view('dashboard/admin', $data);
    }
}

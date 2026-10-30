<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\Reports\AsrAcdService;
use Ownswitch\Services\Reports\FinancialReportService;

final class ReportController extends BaseController
{
    public function asrAcd(Request $r, array $p = []): Response
    {
        $scope = $this->scope($r);
        [$from, $to] = $this->dateRange($r, 7);
        $group = is_string($r->query('group')) ? (string) $r->query('group') : 'day';
        $period = in_array($r->query('period'), ['week', 'month'], true) ? (string) $r->query('period') : null;
        $svc = new AsrAcdService($this->db());

        $rows = $svc->live($scope, $from, $to, isset(AsrAcdService::GROUPS[$group]) ? $group : 'day');
        $periodRows = $period ? $svc->periodFromAggregates($scope, $from, $to, $period) : [];
        $total = AsrAcdService::aggregate(array_map(static fn ($x) => ['attempts' => $x['attempts'], 'answered' => $x['answered'], 'seconds' => $x['seconds']], $rows));

        return $this->view('reports/asr_acd', [
            'title' => 'ASR / ACD', 'rows' => $rows, 'total' => $total, 'from' => $from, 'to' => $to, 'group' => $group,
            'period' => $period, 'periodRows' => $periodRows, 'canCarrier' => $scope->isGlobal(),
        ]);
    }

    public function financial(Request $r, array $p = []): Response
    {
        [$from, $to] = $this->dateRange($r, 30);
        $group = is_string($r->query('group')) ? (string) $r->query('group') : 'day';
        $res = (new FinancialReportService($this->db()))->summary($this->scope($r), $from, $to, $group);
        return $this->view('reports/financial', [
            'title' => 'Financial Report', 'res' => $res, 'from' => $from, 'to' => $to, 'group' => $group, 'canCarrier' => $this->scope($r)->isGlobal(),
        ]);
    }
}

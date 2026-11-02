<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\Reports\CallSummaryService;
use Ownswitch\Services\Reports\FinancialReportService;
use Ownswitch\Support\Menu;

final class ReportController extends BaseController
{
    /** Magnus-style call summary (day / month / user / trunk / destination). */
    public function callSummary(Request $r, array $p = []): Response
    {
        $slug = (string) ($p['slug'] ?? 'summary-day');
        if (!isset(CallSummaryService::REPORTS[$slug])) {
            return $this->notFound();
        }
        $meta = CallSummaryService::REPORTS[$slug];
        $scope = $this->scope($r);
        $role = (string) ($this->user($r)['role'] ?? '');
        if ($meta['staff_only'] && !in_array($role, Menu::STAFF, true) && !$scope->isGlobal()) {
            return $this->forbidden();
        }

        [$from, $to] = $this->dateRange($r, $meta['bucket'] === 'month' ? 90 : 7);
        $res = (new CallSummaryService($this->db()))->run($slug, $scope, $from, $to);

        $reportLinks = [];
        foreach (CallSummaryService::REPORTS as $key => $rep) {
            if ($rep['staff_only'] && !in_array($role, Menu::STAFF, true) && !$scope->isGlobal()) {
                continue;
            }
            $reportLinks[] = ['label' => $rep['title'], 'path' => '/reports/' . $key];
        }

        return $this->view('reports/call_summary', [
            'title' => $meta['title'],
            'slug' => $slug,
            'from' => $from,
            'to' => $to,
            'res' => $res,
            'reportLinks' => $reportLinks,
        ]);
    }

    /** @deprecated kept for old bookmarks — redirects into call summary */
    public function asrAcd(Request $r, array $p = []): Response
    {
        return $this->callSummary($r, ['slug' => 'summary-day']);
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

    /** Failed CDRs shortcut (Magnus CDR Failed). */
    public function cdrFailed(Request $r, array $p = []): Response
    {
        $q = $r->query;
        $q['disposition'] = 'FAILED';
        $qs = http_build_query($q);
        return Response::redirect(url('/cdrs' . ($qs !== '' ? '?' . $qs : '?disposition=FAILED')));
    }
}

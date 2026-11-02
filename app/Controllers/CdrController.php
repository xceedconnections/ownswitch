<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Repositories\CustomerRepository;
use Ownswitch\Services\Cdr\CdrSearchService;

final class CdrController extends BaseController
{
    /** @return array<string,mixed> whitelisted string filters from the query string */
    private function filters(Request $r): array
    {
        [$from, $to] = $this->dateRange($r, 1);
        $f = ['from' => $from, 'to' => $to];
        foreach (['customer_id', 'carrier_id', 'src', 'dst', 'disposition', 'min_duration'] as $k) {
            $v = $r->query($k);
            if (is_string($v) && $v !== '') {
                $f[$k] = $v;
            }
        }
        return $f;
    }

    public function index(Request $r, array $p = []): Response
    {
        $scope = $this->scope($r);
        $svc = new CdrSearchService($this->db());
        $f = $this->filters($r);
        $res = $svc->search($scope, $f, (int) $r->query('page', 1), 50,
            is_string($r->query('sort')) ? (string) $r->query('sort') : 'setup_at', is_string($r->query('dir')) ? (string) $r->query('dir') : 'desc');
        $customers = [];
        if (!$scope->isCustomerRole()) {
            foreach ((new CustomerRepository($this->db()))->all($scope, [], 500, 'name') as $c) {
                $customers[$c['id']] = $c['name'];
            }
        }
        $carriers = [];
        if ($scope->isGlobal()) {
            foreach ($this->db()->query('SELECT id, name FROM carriers ORDER BY name LIMIT 500')->fetchAll() as $c) {
                $carriers[$c['id']] = $c['name'];
            }
        }
        return $this->view('cdr/search', [
            'title' => 'CDR Search', 'result' => $res, 'f' => $f, 'customers' => $customers, 'carriers' => $carriers,
            // Charge (sell) always shown; carrier cost/margin only for staff/global
            'dispositions' => CdrSearchService::DISPOSITIONS, 'showCost' => $scope->isGlobal(), 'query' => $r->query,
        ]);
    }

    public function show(Request $r, array $p): Response
    {
        $row = (new CdrSearchService($this->db()))->find($this->scope($r), (int) $p['id']);
        if ($row === null) {
            return $this->notFound('CDR not found');
        }
        return $this->view('cdr/show', ['title' => 'CDR #' . $row['id'], 'cdr' => $row, 'showCost' => $this->scope($r)->isGlobal()]);
    }

    public function export(Request $r, array $p = []): Response
    {
        $scope = $this->scope($r);
        $filters = $this->filters($r);
        $svc = new CdrSearchService($this->db());
        $this->audit($r, 'cdr_export', 'cdrs', null, null, $filters);
        return Response::stream(
            static function () use ($svc, $scope, $filters): void {
                $svc->exportCsv($scope, $filters, static function (string $chunk): void {
                    echo $chunk;
                });
            },
            'text/csv; charset=UTF-8',
            ['Content-Disposition' => 'attachment; filename="cdrs-' . gmdate('Ymd-His') . '.csv"']
        );
    }
}

<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Repositories\BaseRepository;
use Ownswitch\Services\Fraud\SignalingFraudService;

final class FraudController extends BaseController
{
    private function events(): BaseRepository
    {
        return new BaseRepository($this->db(), 'fraud_events', ['status', 'customer_id', 'rule_code', 'severity', 'acknowledged_by'], 'owned', ['summary', 'rule_code'], ['id', 'created_at', 'severity']);
    }

    public function index(Request $r, array $p = []): Response
    {
        $scope = $this->scope($r);
        $filters = is_string($r->query('status')) && $r->query('status') !== '' ? ['status' => $r->query('status')] : [];
        $res = $this->events()->paginate($scope, $filters, (int) $r->query('page', 1), 25);
        $rules = $scope->isStaff() ? $this->db()->query('SELECT * FROM fraud_rules ORDER BY id')->fetchAll() : [];
        return $this->view('fraud/index', ['title' => 'Fraud Alerts', 'result' => $res, 'rules' => $rules, 'canManage' => $scope->isStaff(), 'filters' => $filters]);
    }

    public function scan(Request $r, array $p = []): Response
    {
        $n = (new SignalingFraudService($this->db()))->scan();
        $this->audit($r, 'fraud_scan', 'fraud_events', null, null, ['new_events' => $n]);
        return $this->redirect($r, '/fraud', $n > 0 ? "{$n} new fraud event(s) detected." : 'Scan complete: nothing new.');
    }

    public function acknowledge(Request $r, array $p): Response
    {
        $scope = $this->scope($r);
        $ok = $this->events()->update((int) $p['id'], ['status' => 'acknowledged', 'acknowledged_by' => (int) $this->user($r)['id']], $scope);
        if ($ok) {
            $this->audit($r, 'fraud_ack', 'fraud_events', (int) $p['id']);
        }
        return $this->redirect($r, '/fraud', $ok ? 'Event acknowledged.' : 'Event not found.', $ok ? 'success' : 'danger');
    }
}

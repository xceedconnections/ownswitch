<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Repositories\CustomerRepository;

/** Account statements from ledger entries (opening → movements → closing). */
final class StatementController extends BaseController
{
    public function index(Request $r, array $p = []): Response
    {
        [$from, $to] = $this->dateRange($r, 30);
        $scope = $this->scope($r);
        $cid = is_string($r->query('customer_id')) && ctype_digit((string) $r->query('customer_id'))
            ? (int) $r->query('customer_id') : null;
        $customers = $this->customerOptions($r);
        $statement = null;
        $error = null;
        if ($cid !== null) {
            $cust = (new CustomerRepository($this->db()))->find($cid, $scope);
            if ($cust === null) {
                $error = 'User not found.';
            } else {
                $statement = $this->build($cid, $from, $to, $cust);
            }
        }
        return $this->view('statements/index', [
            'title' => 'Account Statements',
            'customers' => $customers,
            'customer_id' => $cid !== null ? (string) $cid : '',
            'from' => $from,
            'to' => $to,
            'statement' => $statement,
            'error' => $error,
        ]);
    }

    /**
     * @param array<string,mixed> $cust
     * @return array<string,mixed>
     */
    private function build(int $customerId, string $from, string $to, array $cust): array
    {
        $fromTs = $from . ' 00:00:00';
        $toTs = (new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d 00:00:00');
        $openSt = $this->db()->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM ledger_entries
             WHERE customer_id = ? AND affects_balance = 1 AND created_at < ?"
        );
        $openSt->execute([$customerId, $fromTs]);
        $opening = (string) $openSt->fetchColumn();

        $st = $this->db()->prepare(
            "SELECT id, entry_type, amount, currency, description, created_at, cdr_id, external_ref
             FROM ledger_entries
             WHERE customer_id = ? AND affects_balance = 1
               AND created_at >= ? AND created_at < ?
             ORDER BY created_at ASC, id ASC"
        );
        $st->execute([$customerId, $fromTs, $toTs]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $running = $opening;
        $out = [];
        foreach ($rows as $row) {
            $running = bcadd($running, (string) $row['amount'], 6);
            $row['balance_after'] = $running;
            $out[] = $row;
        }
        $balSt = $this->db()->prepare('SELECT balance FROM balances WHERE customer_id = ?');
        $balSt->execute([$customerId]);
        $live = (string) ($balSt->fetchColumn() ?: '0');

        return [
            'customer' => $cust,
            'opening' => $opening,
            'closing' => $running,
            'live_balance' => $live,
            'rows' => $out,
            'from' => $from,
            'to' => $to,
        ];
    }

    /** @return array<int|string,string> */
    private function customerOptions(Request $r): array
    {
        $o = [];
        foreach ((new CustomerRepository($this->db()))->all($this->scope($r), [], 500, 'name') as $c) {
            $o[$c['id']] = "{$c['name']} ({$c['code']})";
        }
        return $o;
    }
}

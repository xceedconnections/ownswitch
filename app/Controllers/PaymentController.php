<?php

declare(strict_types=1);

namespace Ownswitch\Controllers;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Repositories\CustomerRepository;
use Ownswitch\Services\Billing\LedgerService;
use Ownswitch\Services\Billing\RatingService;

/** Record customer payments against invoices / account balance. */
final class PaymentController extends BaseController
{
    public function index(Request $r, array $p = []): Response
    {
        $scope = $this->scope($r);
        $page = max(1, (int) $r->query('page', 1));
        $per = 25;
        $cid = is_string($r->query('customer_id')) && ctype_digit((string) $r->query('customer_id'))
            ? (int) $r->query('customer_id') : null;
        [$tw, $tp] = $scope->where('owned', 'c');
        $where = ["({$tw})"];
        $params = $tp;
        if ($cid !== null) {
            $where[] = 'p.customer_id = ?';
            $params[] = $cid;
        }
        $sqlWhere = implode(' AND ', $where);
        try {
            $stc = $this->db()->prepare(
                "SELECT COUNT(*) FROM payments p JOIN customers c ON c.id = p.customer_id WHERE {$sqlWhere}"
            );
            $stc->execute($params);
            $total = (int) $stc->fetchColumn();
            $st = $this->db()->prepare(
                "SELECT p.*, c.name AS customer_name, c.code AS customer_code
                 FROM payments p
                 JOIN customers c ON c.id = p.customer_id
                 WHERE {$sqlWhere}
                 ORDER BY p.id DESC
                 LIMIT {$per} OFFSET " . (($page - 1) * $per)
            );
            $st->execute($params);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[ownswitch] payments: ' . $e->getMessage());
            $total = 0;
            $rows = [];
        }
        return $this->view('payments/index', [
            'title' => 'Payments',
            'result' => ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => max(1, (int) ceil($total / $per))],
            'customers' => $this->customerOptions($r),
            'customer_id' => $cid !== null ? (string) $cid : '',
            'canWrite' => in_array($this->user($r)['role'] ?? '', ['super_admin', 'admin', 'reseller'], true),
            'query' => $r->query,
        ]);
    }

    public function create(Request $r, array $p = []): Response
    {
        if (!in_array($this->user($r)['role'] ?? '', ['super_admin', 'admin', 'reseller'], true)) {
            return $this->forbidden();
        }
        return $this->view('payments/form', [
            'title' => 'Record Payment',
            'customers' => $this->customerOptions($r),
            'invoices' => $this->openInvoices($r),
            'values' => ['customer_id' => '', 'invoice_id' => '', 'amount' => '', 'method' => 'bank', 'reference' => ''],
            'errors' => [],
        ]);
    }

    public function store(Request $r, array $p = []): Response
    {
        if (!in_array($this->user($r)['role'] ?? '', ['super_admin', 'admin', 'reseller'], true)) {
            return $this->forbidden();
        }
        $scope = $this->scope($r);
        $v = [
            'customer_id' => (string) ($r->post['customer_id'] ?? ''),
            'invoice_id' => (string) ($r->post['invoice_id'] ?? ''),
            'amount' => trim((string) ($r->post['amount'] ?? '')),
            'method' => trim((string) ($r->post['method'] ?? 'bank')),
            'reference' => trim((string) ($r->post['reference'] ?? '')),
        ];
        $errors = [];
        $cust = ctype_digit($v['customer_id']) ? (new CustomerRepository($this->db()))->find((int) $v['customer_id'], $scope) : null;
        if ($cust === null) {
            $errors[] = 'Select a valid user.';
        }
        if (!preg_match('/^\d+(\.\d{1,6})?$/', $v['amount']) || bccomp($v['amount'], '0', 6) <= 0) {
            $errors[] = 'Amount must be a positive decimal (max 6 places).';
        }
        if (!in_array($v['method'], ['bank', 'cash', 'card', 'wallet', 'other'], true)) {
            $errors[] = 'Invalid payment method.';
        }
        if (mb_strlen($v['reference']) > 190) {
            $errors[] = 'Reference is too long.';
        }
        $invoiceId = null;
        if ($v['invoice_id'] !== '') {
            if (!ctype_digit($v['invoice_id'])) {
                $errors[] = 'Invalid invoice.';
            } else {
                $invoiceId = (int) $v['invoice_id'];
                $st = $this->db()->prepare('SELECT id, customer_id FROM invoices WHERE id = ?');
                $st->execute([$invoiceId]);
                $inv = $st->fetch(\PDO::FETCH_ASSOC);
                if (!$inv || (int) $inv['customer_id'] !== (int) ($cust['id'] ?? 0)) {
                    $errors[] = 'Invoice does not belong to this user.';
                }
            }
        }
        if ($errors) {
            return $this->view('payments/form', [
                'title' => 'Record Payment',
                'customers' => $this->customerOptions($r),
                'invoices' => $this->openInvoices($r),
                'values' => $v,
                'errors' => $errors,
            ], 422);
        }

        $amount = RatingService::round($v['amount'], 6);
        $nonce = is_string($r->post['nonce'] ?? null) ? (string) $r->post['nonce'] : bin2hex(random_bytes(8));
        $ledger = (new LedgerService($this->db()))->post([
            'customer_id' => (int) $cust['id'],
            'reseller_id' => $cust['reseller_id'] !== null ? (int) $cust['reseller_id'] : null,
            'entry_type' => 'invoice_payment',
            'amount' => $amount,
            'currency' => (string) $cust['currency'],
            'idempotency_key' => "payment:{$cust['id']}:{$nonce}",
            'description' => 'Payment' . ($invoiceId ? " for invoice #{$invoiceId}" : '') . ($v['reference'] !== '' ? " ({$v['reference']})" : ''),
            'created_by' => (int) $this->user($r)['id'],
            'external_ref' => $v['reference'] !== '' ? $v['reference'] : null,
        ]);
        $this->db()->prepare(
            'INSERT INTO payments (customer_id, invoice_id, amount, method, reference, ledger_entry_id, created_by) VALUES (?,?,?,?,?,?,?)'
        )->execute([
            (int) $cust['id'], $invoiceId, $amount, $v['method'],
            $v['reference'] !== '' ? $v['reference'] : null,
            $ledger['id'], (int) $this->user($r)['id'],
        ]);
        $pid = (int) $this->db()->lastInsertId();
        if ($invoiceId !== null && !$ledger['duplicate']) {
            $paidSt = $this->db()->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = ?');
            $paidSt->execute([$invoiceId]);
            $paid = (string) $paidSt->fetchColumn();
            $totSt = $this->db()->prepare('SELECT total FROM invoices WHERE id = ?');
            $totSt->execute([$invoiceId]);
            $total = (string) ($totSt->fetchColumn() ?: '0');
            if (bccomp($paid, $total, 6) >= 0) {
                $this->db()->prepare("UPDATE invoices SET status = 'paid' WHERE id = ? AND status <> 'void'")->execute([$invoiceId]);
            }
        }
        $this->audit($r, 'payment_create', 'payments', $pid, null, ['customer_id' => $cust['id'], 'amount' => $amount, 'invoice_id' => $invoiceId]);
        return $this->redirect($r, '/payments', $ledger['duplicate'] ? 'Payment already recorded (duplicate submit ignored).' : 'Payment recorded and balance updated.');
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

    /** @return array<int|string,string> */
    private function openInvoices(Request $r): array
    {
        $out = ['' => '— No invoice —'];
        try {
            [$tw, $tp] = $this->scope($r)->where('owned', 'c');
            $st = $this->db()->prepare(
                "SELECT i.id, i.number, i.total, c.name,
                        (SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.invoice_id = i.id) AS paid
                 FROM invoices i JOIN customers c ON c.id = i.customer_id
                 WHERE ({$tw}) AND i.status = 'issued'
                 ORDER BY i.id DESC LIMIT 300"
            );
            $st->execute($tp);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $due = bcsub((string) $row['total'], (string) $row['paid'], 6);
                $out[(int) $row['id']] = "{$row['number']} · {$row['name']} — due {$due}";
            }
        } catch (\Throwable) {
            // invoices schema may vary by status values
        }
        return $out;
    }
}

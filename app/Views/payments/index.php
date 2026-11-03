<?php
/** @var array $result @var array $customers @var string $customer_id @var bool $canWrite @var array $query */
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Payments <span class="os-count"><?= e((string) $result['total']) ?></span></h1>
        <p class="os-page-sub">Recorded payments against user balances and invoices</p>
    </div>
    <?php if ($canWrite): ?>
        <a class="btn btn-primary btn-sm" href="<?= e(url('/payments/create')) ?>"><i class="bi bi-plus-lg me-1"></i>Record payment</a>
    <?php endif; ?>
</div>

<form method="get" class="os-toolbar row g-2 align-items-end mb-3" action="<?= e(url('/payments')) ?>">
    <div class="col-12 col-md-4">
        <label class="form-label small mb-1" for="customer_id">User</label>
        <select class="form-select form-select-sm" id="customer_id" name="customer_id" data-autosubmit>
            <option value="">All users</option>
            <?php foreach ($customers as $id => $label): ?>
                <option value="<?= e((string) $id) ?>"<?= $customer_id === (string) $id ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto"><button class="btn btn-outline-secondary btn-sm" type="submit">Filter</button></div>
</form>

<div class="os-table-wrap">
<?php if ($result['total'] === 0): ?>
    <div class="os-empty">
        <div class="os-empty-title">No payments</div>
        <div>Record a payment to credit a user’s balance (and optionally close an invoice).</div>
        <?php if ($canWrite): ?><a class="btn btn-primary btn-sm mt-3" href="<?= e(url('/payments/create')) ?>">Record the first payment</a><?php endif; ?>
    </div>
<?php else: ?>
    <div class="table-responsive"><table class="table table-sm table-hover mb-0 os-table">
        <thead><tr><th>ID</th><th>Time (UTC)</th><th>User</th><th>Method</th><th>Reference</th><th>Invoice</th><th class="os-num">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($result['rows'] as $row): ?>
            <tr>
                <td class="os-mono"><?= e((string) $row['id']) ?></td>
                <td class="os-mono"><?= e(substr((string) $row['created_at'], 0, 19)) ?></td>
                <td><?= e((string) $row['customer_name']) ?> <span class="text-muted os-mono"><?= e((string) $row['customer_code']) ?></span></td>
                <td><?= e((string) ($row['method'] ?? '—')) ?></td>
                <td class="os-mono"><?= e((string) ($row['reference'] ?? '—')) ?></td>
                <td class="os-mono"><?= e((string) ($row['invoice_id'] ?? '—')) ?></td>
                <td class="os-num os-mono"><?= e((string) $row['amount']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php include __DIR__ . '/../partials/pagination.php'; ?>
<?php endif; ?>
</div>

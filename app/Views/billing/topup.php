<?php /** @var array $customers @var array $values @var list<string> $errors */ ?>
<h1 class="h4 mb-3">Top-up / Adjustment</h1>
<p class="text-muted small">Posts an append-only ledger entry. Entries cannot be edited or deleted; mistakes are fixed with a reversal from the Ledger page.</p>
<?php foreach ($errors as $err): ?><div class="alert alert-danger py-2"><?= e($err) ?></div><?php endforeach; ?>
<?php if (!$customers): ?>
    <div class="card shadow-sm"><div class="os-empty"><div class="os-empty-title">No customers</div><div>Create a customer first.</div></div></div>
<?php else: ?>
<form method="post" action="<?= e(url('/billing/topup')) ?>" class="card card-body shadow-sm col-xl-6">
    <?= csrf_field() ?>
    <input type="hidden" name="nonce" value="<?= e(bin2hex(random_bytes(8))) ?>">
    <div class="mb-3"><label class="form-label" for="customer_id">Customer</label>
        <select class="form-select" id="customer_id" name="customer_id" required><option value="">- select -</option>
            <?php foreach ($customers as $id => $n): ?><option value="<?= e($id) ?>"<?= (string) $values['customer_id'] === (string) $id ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
        </select></div>
    <div class="mb-3"><label class="form-label" for="type">Type</label>
        <select class="form-select" id="type" name="type">
            <option value="topup"<?= $values['type'] === 'topup' ? ' selected' : '' ?>>Top-up (credit)</option>
            <option value="adjustment"<?= $values['type'] === 'adjustment' ? ' selected' : '' ?>>Adjustment (+/-)</option>
            <option value="refund"<?= $values['type'] === 'refund' ? ' selected' : '' ?>>Refund (debit)</option>
        </select></div>
    <div class="mb-3"><label class="form-label" for="amount">Amount</label>
        <input class="form-control" id="amount" name="amount" value="<?= e($values['amount']) ?>" inputmode="decimal" maxlength="20" required>
        <div class="form-text">Positive decimal, up to 6 places. Adjustments may be negative.</div></div>
    <div class="mb-3"><label class="form-label" for="description">Description / reason</label>
        <input class="form-control" id="description" name="description" value="<?= e($values['description']) ?>" maxlength="200" required></div>
    <button class="btn btn-primary" type="submit" data-once>Post entry</button>
</form>
<?php endif; ?>

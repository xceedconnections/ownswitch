<?php
/** @var ?array $customer @var string $balance @var string $available @var int $sipCount @var array $today @var int $totalCdrs */
$cur = $customer['currency'] ?? '';
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Welcome<?= $customer ? ', ' . e($customer['name']) : '' ?></h1>
        <p class="os-page-sub">Account usage and balance at a glance</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/calls-online')) ?>"><i class="bi bi-broadcast me-1"></i>Live calls</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/cdrs')) ?>"><i class="bi bi-telephone-forward me-1"></i>CDRs</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/reports/summary-day')) ?>"><i class="bi bi-graph-up me-1"></i>Reports</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/ledger')) ?>"><i class="bi bi-wallet2 me-1"></i>Ledger</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/did-assignments')) ?>"><i class="bi bi-telephone me-1"></i>My DIDs</a>
    </div>
</div>

<?php if (!$customer): ?>
    <div class="alert alert-danger">Your account is not linked to a customer record. Contact support.</div>
<?php else: ?>
    <?php if ($customer['status'] !== 'active'): ?>
        <div class="alert alert-warning">Your account status is <strong><?= e($customer['status']) ?></strong>. New calls may be rejected.</div>
    <?php endif; ?>
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3"><div class="os-card-stat h-100"><div class="card-body">
            <div class="os-stat-label">Balance (<?= e($cur) ?>)</div>
            <div class="os-stat-value os-mono"><?= e(fmt_money($balance, 4)) ?></div>
            <div class="small text-muted mt-1"><?= e(ucfirst((string) $customer['billing_mode'])) ?></div>
        </div></div></div>
        <div class="col-6 col-xl-3"><div class="os-card-stat h-100"><div class="card-body">
            <div class="os-stat-label">Available to spend</div>
            <div class="os-stat-value os-mono"><?= e(fmt_money($available, 4)) ?></div>
            <div class="small text-muted mt-1">after reservations<?= $customer['billing_mode'] === 'postpaid' ? ' + credit limit' : '' ?></div>
        </div></div></div>
        <div class="col-6 col-xl-3"><div class="os-card-stat h-100"><div class="card-body">
            <div class="os-stat-label">Active SIP users</div>
            <div class="os-stat-value"><a href="<?= e(url('/sip-users')) ?>"><?= e($sipCount) ?></a></div>
        </div></div></div>
        <div class="col-6 col-xl-3"><div class="os-card-stat h-100"><div class="card-body">
            <div class="os-stat-label">Calls today (UTC)</div>
            <div class="os-stat-value"><a href="<?= e(url('/cdrs')) ?>"><?= e($today['attempts']) ?></a></div>
            <div class="small text-muted mt-1"><?= e($today['answered']) ?> answered</div>
        </div></div></div>
    </div>
    <?php if ($totalCdrs === 0): ?>
        <div class="os-panel"><div class="os-empty">
            <div class="os-empty-title">No calls yet</div>
            <div>Your call history will appear here after your first call.</div>
        </div></div>
    <?php endif; ?>
<?php endif; ?>

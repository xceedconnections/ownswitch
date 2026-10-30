<?php /** @var array $result @var list<array> $rules @var bool $canManage @var array $filters */ $query = $_GET ?? []; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Fraud Alerts <span class="badge text-bg-light border"><?= e($result['total']) ?></span></h1>
    <?php if ($canManage): ?>
        <form method="post" action="<?= e(url('/fraud/scan')) ?>"><?= csrf_field() ?><button class="btn btn-outline-primary btn-sm" type="submit" data-once>Scan recent CDRs now</button></form>
    <?php endif; ?>
</div>
<p class="text-muted small">Signalling/CDR-based detection only (velocity, short-call ratio, destination spread). No audio analysis is performed.</p>
<div class="card shadow-sm mb-4">
<?php if ($result['total'] === 0): ?>
    <div class="os-empty"><div class="os-empty-title">No fraud events</div><div>Nothing has been flagged. Run <code>php bin/worker_cdr.php</code> to ingest CDRs, then scan.</div></div>
<?php else: ?>
    <div class="table-responsive"><table class="table table-sm table-hover mb-0 os-table">
        <thead><tr><th>Time (UTC)</th><th>Rule</th><th>Severity</th><th>Customer</th><th>Summary</th><th>Status</th><th></th></tr></thead>
        <tbody><?php foreach ($result['rows'] as $ev): ?>
            <tr><td class="os-mono"><?= e(substr((string) $ev['created_at'], 0, 19)) ?></td><td class="os-mono"><?= e($ev['rule_code']) ?></td>
                <td><span class="badge text-bg-<?= $ev['severity'] === 'high' ? 'danger' : 'warning' ?>"><?= e($ev['severity']) ?></span></td>
                <td class="os-mono"><?= e($ev['customer_id']) ?></td><td><?= e($ev['summary']) ?></td><td><?= e($ev['status']) ?></td>
                <td class="os-actions"><?php if ($ev['status'] === 'open'): ?>
                    <form method="post" action="<?= e(url('/fraud/' . (int) $ev['id'] . '/ack')) ?>"><?= csrf_field() ?><button class="btn btn-outline-secondary btn-sm" type="submit">Acknowledge</button></form><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php include __DIR__ . '/../partials/pagination.php'; ?>
<?php endif; ?>
</div>
<?php if ($rules): ?>
    <h2 class="h6 text-uppercase text-muted">Active rules</h2>
    <div class="card shadow-sm"><div class="table-responsive"><table class="table table-sm mb-0 os-table">
        <thead><tr><th>Code</th><th>Type</th><th class="os-num">Threshold</th><th class="os-num">Window (s)</th><th>Action</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($rules as $r): ?>
            <tr><td class="os-mono"><?= e($r['code']) ?></td><td><?= e($r['rule_type']) ?></td><td class="os-num"><?= e($r['threshold']) ?></td><td class="os-num"><?= e($r['window_seconds']) ?></td><td><?= e($r['action']) ?></td><td><?= e($r['status']) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div></div>
<?php endif; ?>

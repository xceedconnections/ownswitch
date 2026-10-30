<?php /** @var list<array> $checks @var array $counts @var string $php @var string $time */
$badge = ['ok' => 'success', 'warn' => 'warning', 'fail' => 'danger', 'unconfigured' => 'secondary'];
?>
<h1 class="h4 mb-3">System Status</h1>
<p class="text-muted small">PHP <?= e($php) ?> &middot; <?= e($time) ?>. Components that are not configured are reported as such; nothing is faked.</p>
<div class="row g-3">
    <div class="col-lg-8"><div class="card shadow-sm"><div class="table-responsive"><table class="table table-sm mb-0 os-table">
        <thead><tr><th>Component</th><th>Status</th><th>Detail</th></tr></thead>
        <tbody><?php foreach ($checks as $c): ?>
            <tr><td><?= e($c['component']) ?></td><td><span class="badge text-bg-<?= e($badge[$c['status']] ?? 'secondary') ?>"><?= e($c['status']) ?></span></td><td><?= e($c['detail']) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div></div></div>
    <div class="col-lg-4"><div class="card shadow-sm"><div class="card-header">Row counts</div><table class="table table-sm mb-0">
        <?php foreach ($counts as $t => $n): ?><tr><td class="os-mono"><?= e($t) ?></td><td class="os-num"><?= e($n) ?></td></tr><?php endforeach; ?>
    </table></div></div>
</div>

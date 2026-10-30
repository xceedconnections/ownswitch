<?php
/** @var array $res @var string $from @var string $to @var string $group @var bool $canCarrier */
$groups = ['day' => 'Day', 'customer' => 'Customer', 'currency' => 'Currency'] + ($canCarrier ? ['carrier' => 'Carrier'] : []);
$showCost = $res['show_cost'];
?>
<h1 class="h4 mb-3">Financial Report</h1>
<form method="get" action="<?= e(url('/reports/financial')) ?>" class="card card-body shadow-sm mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="from">From</label><input type="date" class="form-control form-control-sm" id="from" name="from" value="<?= e($from) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="to">To</label><input type="date" class="form-control form-control-sm" id="to" name="to" value="<?= e($to) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="group">Group by</label>
            <select class="form-select form-select-sm" id="group" name="group"><?php foreach ($groups as $k => $l): ?><option value="<?= e($k) ?>"<?= $group === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-auto"><button class="btn btn-primary btn-sm" type="submit">Run</button></div>
    </div>
</form>
<div class="card shadow-sm">
<?php if (!$res['rows']): ?>
    <div class="os-empty"><div class="os-empty-title">No data</div><div>No CDRs for this period.</div></div>
<?php else: ?>
    <?php if (str_contains($res['totals']['currencies'], ',')): ?><div class="alert alert-warning m-2 mb-0 py-1 small">Multiple currencies are present; the totals row adds raw numbers and is not meaningful. Group by currency.</div><?php endif; ?>
    <div class="table-responsive"><table class="table table-sm mb-0 os-table">
        <thead><tr><th><?= e($groups[$group] ?? 'Group') ?></th><th>Cur</th><th class="os-num">Calls</th><th class="os-num">Billed calls</th><th class="os-num">Billable s</th><th class="os-num">Revenue</th>
            <?php if ($showCost): ?><th class="os-num">Cost</th><th class="os-num">Margin</th><th class="os-num">Margin %</th><?php endif; ?></tr></thead>
        <tbody><?php foreach ($res['rows'] as $r): ?>
            <tr><td class="os-mono"><?= e($r['label']) ?></td><td><?= e($r['currency']) ?></td><td class="os-num"><?= e($r['calls']) ?></td><td class="os-num"><?= e($r['billed_calls']) ?></td>
                <td class="os-num"><?= e($r['billable_seconds']) ?></td><td class="os-num os-mono"><?= e($r['revenue']) ?></td>
                <?php if ($showCost): ?><td class="os-num os-mono"><?= e($r['cost']) ?></td><td class="os-num os-mono"><?= e($r['margin']) ?></td><td class="os-num"><?= e($r['margin_pct']) ?></td><?php endif; ?></tr>
        <?php endforeach; ?></tbody>
        <tfoot><tr class="table-light fw-semibold"><td>Total</td><td></td><td class="os-num"><?= e((int) $res['totals']['calls']) ?></td><td class="os-num"><?= e((int) $res['totals']['billed_calls']) ?></td>
            <td class="os-num"><?= e((int) $res['totals']['billable_seconds']) ?></td><td class="os-num os-mono"><?= e($res['totals']['revenue']) ?></td>
            <?php if ($showCost): ?><td class="os-num os-mono"><?= e($res['totals']['cost']) ?></td><td class="os-num os-mono"><?= e($res['totals']['margin']) ?></td><td class="os-num"><?= e($res['totals']['margin_pct']) ?></td><?php endif; ?></tr></tfoot>
    </table></div>
<?php endif; ?>
</div>

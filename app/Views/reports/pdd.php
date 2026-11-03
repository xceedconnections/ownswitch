<?php
/** @var array $res @var string $from @var string $to */
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">PDD Report</h1>
        <p class="os-page-sub">Post-dial delay: setup → answer (answered calls only)</p>
    </div>
</div>
<form method="get" action="<?= e(url('/reports/pdd')) ?>" class="os-toolbar mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="from">From</label><input type="date" class="form-control form-control-sm" id="from" name="from" value="<?= e($from) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="to">To</label><input type="date" class="form-control form-control-sm" id="to" name="to" value="<?= e($to) ?>"></div>
        <div class="col-auto"><button class="btn btn-primary btn-sm" type="submit">Run</button></div>
    </div>
</form>
<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="os-kpi"><div class="lbl">Answered</div><div class="val"><?= e(number_format((int) $res['answered'])) ?></div></div></div>
    <div class="col-md-3"><div class="os-kpi"><div class="lbl">Avg PDD</div><div class="val"><?= e($res['avg_pdd_ms']) ?><span class="sub"> ms</span></div></div></div>
    <div class="col-md-3"><div class="os-kpi"><div class="lbl">P50</div><div class="val"><?= e($res['p50_ms']) ?><span class="sub"> ms</span></div></div></div>
    <div class="col-md-3"><div class="os-kpi"><div class="lbl">P95</div><div class="val"><?= e($res['p95_ms']) ?><span class="sub"> ms</span></div></div></div>
</div>
<div class="os-table-wrap">
<?php if (!$res['rows']): ?>
    <div class="os-empty"><div class="os-empty-title">No answered calls</div><div>No PDD samples in this range.</div></div>
<?php else: ?>
    <div class="table-responsive"><table class="table table-sm mb-0 os-table">
        <thead><tr><th>Trunk / carrier</th><th class="os-num">Answered</th><th class="os-num">Avg PDD (ms)</th></tr></thead>
        <tbody>
        <?php foreach ($res['rows'] as $row): ?>
            <tr>
                <td><?= e((string) $row['label']) ?></td>
                <td class="os-num"><?= e(number_format((int) $row['answered'])) ?></td>
                <td class="os-num os-mono"><?= e((string) $row['avg_pdd_ms']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
</div>

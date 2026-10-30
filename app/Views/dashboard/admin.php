<?php
/** @var array $today @var int $totalCdrs @var bool $showDemo @var bool $isGlobal @var array $counts */
$asr = \Ownswitch\Services\Reports\AsrAcdService::compute($today['attempts'], $today['answered'], 0)['asr'];
$tile = static function (string $label, mixed $value, string $href = '', string $hint = ''): void { ?>
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm os-card-stat h-100"><div class="card-body">
            <div class="os-stat-label"><?= e($label) ?></div>
            <div class="os-stat-value"><?= $href !== '' ? '<a class="text-decoration-none" href="' . e(url($href)) . '">' . e($value) . '</a>' : e($value) ?></div>
            <?php if ($hint !== ''): ?><div class="small text-muted"><?= e($hint) ?></div><?php endif; ?>
        </div></div>
    </div>
<?php };
?>
<h1 class="h4 mb-3">Dashboard</h1>

<?php if ($showDemo): ?>
    <div class="alert alert-warning os-demo-banner">
        <strong>Demo data present.</strong> Rows flagged <code>DEMO</code> were created by the demo seeder and are not real traffic.
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <?php $tile('Customers', $counts['customers'], '/customers'); ?>
    <?php $tile('Active SIP users', $counts['sip'], '/sip-users'); ?>
    <?php if ($counts['resellers'] !== null) { $tile('Resellers', $counts['resellers'], '/resellers'); } ?>
    <?php if ($counts['carriers'] !== null) { $tile('Active carriers', $counts['carriers'], '/carriers'); } ?>
    <?php $tile('Open fraud alerts', $counts['openFraud'], '/fraud'); ?>
</div>

<h2 class="h6 text-uppercase text-muted">Today (UTC)</h2>
<?php if ($totalCdrs === 0): ?>
    <div class="card shadow-sm"><div class="os-empty">
        <div class="os-empty-title">No call records yet</div>
        <div>CDRs appear here once calls are ingested (<code>php bin/worker_cdr.php</code>) or imported. No traffic statistics are shown until then.</div>
    </div></div>
<?php else: ?>
    <div class="row g-3">
        <?php $tile('Call attempts', $today['attempts'], '/cdrs'); ?>
        <?php $tile('Answered', $today['answered']); ?>
        <?php $tile('ASR %', $asr, '/reports/asr-acd'); ?>
        <?php $tile('Revenue', fmt_money($today['revenue'], 4)); ?>
        <?php if ($isGlobal): ?>
            <?php $tile('Carrier cost', fmt_money($today['cost'], 4)); ?>
            <?php $tile('Gross margin', fmt_money(bcsub($today['revenue'], $today['cost'], 6), 4)); ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

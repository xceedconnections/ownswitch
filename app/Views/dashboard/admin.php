<?php
/** @var array $today @var int $totalCdrs @var bool $showDemo @var bool $isGlobal @var array $counts */
$asr = \Ownswitch\Services\Reports\AsrAcdService::compute($today['attempts'], $today['answered'], 0)['asr'];
$tile = static function (string $label, mixed $value, string $href = '', string $hint = ''): void { ?>
    <div class="col-6 col-xl-3">
        <div class="os-card-stat h-100">
            <div class="card-body">
                <div class="os-stat-label"><?= e($label) ?></div>
                <div class="os-stat-value"><?= $href !== '' ? '<a href="' . e(url($href)) . '">' . e($value) . '</a>' : e($value) ?></div>
                <?php if ($hint !== ''): ?><div class="small text-muted mt-1"><?= e($hint) ?></div><?php endif; ?>
            </div>
        </div>
    </div>
<?php };
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Dashboard</h1>
        <p class="os-page-sub">Live platform overview · times in UTC</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/cdrs')) ?>"><i class="bi bi-telephone-forward me-1"></i>CDRs</a>
        <a class="btn btn-primary btn-sm" href="<?= e(url('/customers')) ?>"><i class="bi bi-people me-1"></i>Customers</a>
    </div>
</div>

<?php if ($showDemo): ?>
    <div class="alert os-demo-banner mb-3">
        <strong>Demo data present.</strong> Rows flagged DEMO came from the seeder and are not production traffic.
    </div>
<?php endif; ?>

<div class="os-panel mb-4 p-3 p-md-4">
    <div class="os-section-label mb-2">Quick setup (like MagnusBilling)</div>
    <ol class="mb-0 ps-3 small">
        <li class="mb-1"><a href="<?= e(url('/carriers')) ?>">Add Provider</a> — who you buy minutes from</li>
        <li class="mb-1"><a href="<?= e(url('/trunks')) ?>">Add Trunk</a> — SIP host, user, password</li>
        <li class="mb-1"><a href="<?= e(url('/carrier-tariffs')) ?>">Add Provider Rates</a> — buy prefix / rate</li>
        <li class="mb-1"><a href="<?= e(url('/rate-plans')) ?>">Add Plan</a> — sell increments (I/S)</li>
        <li class="mb-1"><a href="<?= e(url('/tariffs')) ?>">Add Prefixes</a> — sell rates per destination</li>
        <li class="mb-1"><a href="<?= e(url('/routing/plans')) ?>">Add Route Group</a> + <a href="<?= e(url('/routing')) ?>">Routes</a></li>
        <li class="mb-1"><a href="<?= e(url('/customers')) ?>">Add Customer</a> — assign Plan + Route Group</li>
        <li><a href="<?= e(url('/sip-users')) ?>">Add SIP User</a> — phone/PBX account</li>
    </ol>
</div>

<p class="os-section-label">Platform</p>
<div class="row g-3 mb-4">
    <?php $tile('Customers', $counts['customers'], '/customers'); ?>
    <?php $tile('SIP Users', $counts['sip'], '/sip-users'); ?>
    <?php if ($counts['resellers'] !== null) { $tile('Resellers', $counts['resellers'], '/resellers'); } ?>
    <?php if ($counts['carriers'] !== null) { $tile('Providers', $counts['carriers'], '/carriers'); } ?>
    <?php $tile('Open fraud alerts', $counts['openFraud'], '/fraud', 'Review signaling alerts'); ?>
</div>

<p class="os-section-label">Today (UTC)</p>
<?php if ($totalCdrs === 0): ?>
    <div class="os-panel">
        <div class="os-empty">
            <div class="os-empty-title">No call records yet</div>
            <div>CDRs appear after ingest (<code>php bin/worker_cdr.php</code>) or import. Stats stay empty until real traffic arrives.</div>
        </div>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php $tile('Call attempts', $today['attempts'], '/cdrs'); ?>
        <?php $tile('Answered', $today['answered']); ?>
        <?php $tile('ASR %', $asr, '/reports/asr-acd'); ?>
        <?php $tile('Revenue', fmt_money($today['revenue'], 4)); ?>
        <?php if ($isGlobal): ?>
            <?php $tile('Provider cost', fmt_money($today['cost'], 4)); ?>
            <?php $tile('Gross margin', fmt_money(bcsub($today['revenue'], $today['cost'], 6), 4)); ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

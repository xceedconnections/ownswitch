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
        <p class="os-page-sub">MagnusBilling-style ops · times in UTC</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/cdrs')) ?>"><i class="bi bi-telephone-forward me-1"></i>CDR</a>
        <a class="btn btn-primary btn-sm" href="<?= e(url('/users')) ?>"><i class="bi bi-people me-1"></i>Users</a>
    </div>
</div>

<?php if ($showDemo): ?>
    <div class="alert os-demo-banner mb-3">
        <strong>Demo data present.</strong> Rows flagged DEMO came from the seeder and are not production traffic.
    </div>
<?php endif; ?>

<div class="os-panel mb-4 p-3 p-md-4">
    <div class="os-section-label mb-2">First call (MagnusBilling order)</div>
    <ol class="mb-0 ps-3 small">
        <li class="mb-1"><a href="<?= e(url('/users')) ?>">Clients → Users</a> — create user</li>
        <li class="mb-1"><a href="<?= e(url('/rate-plans')) ?>">Rates → Plans</a> — create plan, assign on User</li>
        <li class="mb-1"><a href="<?= e(url('/sip-users')) ?>">Clients → SIP Users</a> — softphone account</li>
        <li class="mb-1"><a href="<?= e(url('/providers')) ?>">Routes → Providers</a> → <a href="<?= e(url('/trunks')) ?>">Trunks</a> → <a href="<?= e(url('/trunk-groups')) ?>">Trunk Groups</a></li>
        <li class="mb-1"><a href="<?= e(url('/prefixes')) ?>">Rates → Prefixes</a> (code + name) → <a href="<?= e(url('/tariffs')) ?>">Tariffs</a> (Plan + Destination + Trunk Group + sell price)</li>
        <li class="mb-1"><a href="<?= e(url('/provider-rates')) ?>">Routes → Provider Rates</a> — buy price</li>
        <li class="mb-1"><a href="<?= e(url('/billing/refill')) ?>">Billing → Refills</a> — add credit</li>
        <li>Register softphone and place a call · check <a href="<?= e(url('/cdrs')) ?>">Reports → CDR</a></li>
    </ol>
</div>

<p class="os-section-label">Platform</p>
<div class="row g-3 mb-4">
    <?php $tile('Users', $counts['customers'], '/users'); ?>
    <?php $tile('SIP Users', $counts['sip'], '/sip-users'); ?>
    <?php $tile('Providers', $counts['carriers'] ?? ($counts['providers'] ?? '—'), '/providers'); ?>
    <?php $tile('CDRs (all time)', $totalCdrs, '/cdrs'); ?>
</div>

<p class="os-section-label">Today (UTC)</p>
<div class="row g-3 mb-4">
    <?php $tile('Attempts', $today['attempts']); ?>
    <?php $tile('Answered', $today['answered']); ?>
    <?php $tile('ASR', $asr . '%'); ?>
    <?php $tile('Sell today', $today['revenue'] ?? '0'); ?>
</div>

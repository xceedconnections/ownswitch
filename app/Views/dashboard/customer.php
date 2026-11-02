<?php
/**
 * @var ?array $customer @var string $balance @var string $available @var ?float $runwayDays
 * @var int $sipCount @var array $metrics
 */
$w = $metrics['window60'];
$t = $metrics['today'];
$failed = $metrics['failed24'];
$traffic = $metrics['traffic60'];
$daily = $metrics['daily7'];
$cur = $customer['currency'] ?? '';
$maxBar = 1;
foreach ($traffic as $b) {
    $maxBar = max($maxBar, $b['answered'] + $b['failed']);
}
$maxMin = 1.0;
foreach ($daily as $d) {
    $maxMin = max($maxMin, (float) $d['minutes']);
}
$acdFmt = static function (string $acd): string {
    $s = (int) round((float) $acd);
    return sprintf('%d:%02d', intdiv($s, 60), $s % 60);
};
$lowRunway = $runwayDays !== null && $runwayDays < 2;
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Dashboard</h1>
        <p class="os-page-sub"><?= $customer ? e($customer['name']) . ' · ' : '' ?>usage, balance &amp; call quality</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/calls-online')) ?>">Live calls</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/cdrs')) ?>">CDR</a>
        <a class="btn btn-primary btn-sm" href="<?= e(url('/ledger')) ?>">Ledger</a>
    </div>
</div>

<?php if (!$customer): ?>
    <div class="alert alert-danger">Your login is not linked to a customer account. Contact support.</div>
<?php else: ?>
    <?php if ($customer['status'] !== 'active'): ?>
        <div class="alert alert-warning">Account status: <strong><?= e($customer['status']) ?></strong>. New calls may be rejected.</div>
    <?php endif; ?>

    <div class="os-banner">
        <div>
            <div class="os-banner-title">
                <span class="os-dot os-dot-ok"></span>
                <?= $w['attempts'] > 0 ? 'Calls flowing' : 'Ready for traffic' ?>
            </div>
            <div class="small text-muted mt-1">
                <?= e(number_format($w['attempts'])) ?> attempts · <?= e($w['asr']) ?>% answered ·
                balance <?= e(fmt_money($available, 2)) ?> <?= e($cur) ?>
                <?php if ($lowRunway): ?> · <a href="<?= e(url('/ledger')) ?>">Low runway — add funds</a><?php endif; ?>
            </div>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/sip-users')) ?>">SIP users</a>
    </div>

    <div class="os-dash-grid os-dash-kpis mb-3">
        <div class="os-kpi">
            <div class="lbl">Attempts (60 min)</div>
            <div class="val"><?= e(number_format($w['attempts'])) ?></div>
            <div class="sub"><?= e(number_format($w['last_min'])) ?> last minute</div>
        </div>
        <div class="os-kpi">
            <div class="lbl">Answered (60 min)</div>
            <div class="val"><?= e(number_format($w['answered'])) ?></div>
            <div class="sub">Avg talk <?= e($acdFmt($w['acd'])) ?></div>
        </div>
        <div class="os-kpi">
            <div class="lbl">Answer rate</div>
            <div class="val"><?= e($w['asr']) ?>%</div>
            <div class="sub">Today <?= e($t['asr']) ?>%</div>
        </div>
        <div class="os-kpi">
            <div class="lbl">Spend (60 min)</div>
            <div class="val os-mono"><?= e(fmt_money($w['spend'], 2)) ?></div>
            <div class="sub"><?= e(fmt_money($t['spend'], 2)) ?> today</div>
        </div>
        <div class="os-kpi">
            <div class="lbl">Available balance</div>
            <div class="val os-mono"><?= e(fmt_money($available, 2)) ?></div>
            <div class="sub">Ledger <?= e(fmt_money($balance, 2)) ?> · <?= e(ucfirst((string) $customer['billing_mode'])) ?></div>
        </div>
        <div class="os-kpi<?= $lowRunway ? ' is-warn' : '' ?>">
            <div class="lbl">Runway</div>
            <div class="val"><?= $runwayDays === null ? '—' : ('≈ ' . e(number_format($runwayDays, 1)) . ' d') ?></div>
            <div class="sub"><?= e((string) $sipCount) ?> active SIP · today pace <?= e(fmt_money($t['spend'], 2)) ?></div>
        </div>
    </div>

    <div class="os-dash-grid os-dash-mid mb-3">
        <div class="os-widget">
            <div class="os-widget-h"><h2 class="os-widget-title">Traffic · last 60 minutes</h2></div>
            <?php if (!$traffic): ?>
                <div class="os-empty py-4"><div class="os-empty-title">No calls yet</div></div>
            <?php else: ?>
                <div class="os-bars">
                    <?php foreach ($traffic as $b):
                        $tot = $b['answered'] + $b['failed'];
                        $h = $tot > 0 ? (100 * $tot / $maxBar) : 0;
                        $ap = $tot > 0 ? (100 * $b['answered'] / $tot) : 0;
                        ?>
                        <div class="os-bar-stack">
                            <div class="os-bar-a" style="height:<?= e((string) round($h * $ap / 100, 1)) ?>%"></div>
                            <div class="os-bar-f" style="height:<?= e((string) round($h * (100 - $ap) / 100, 1)) ?>%"></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="os-legend">
                    <span><i style="background:var(--os-teal)"></i>Answered</span>
                    <span><i style="background:#d9e0e7"></i>Not answered</span>
                </div>
            <?php endif; ?>
        </div>

        <div class="os-widget">
            <div class="os-widget-h">
                <h2 class="os-widget-title">Failed calls · 24 h</h2>
                <span class="small text-muted"><?= e($failed['pct']) ?>%</span>
            </div>
            <div class="mb-2"><strong><?= e(number_format($failed['total'])) ?></strong> <span class="text-muted">of <?= e(number_format($failed['attempts'])) ?></span></div>
            <?php foreach ($failed['reasons'] as $reason): ?>
                <div class="os-fail-row">
                    <div class="meta"><span><?= e($reason['label']) ?></span><span><?= e((string) $reason['pct']) ?>%</span></div>
                    <div class="os-fail-track"><div class="os-fail-fill" style="width:<?= e((string) min(100, $reason['pct'])) ?>%"></div></div>
                </div>
            <?php endforeach; ?>
            <div class="mt-3"><a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/reports/cdr-failed')) ?>">View failed calls</a></div>
        </div>

        <div class="os-widget">
            <div class="os-widget-h">
                <h2 class="os-widget-title">Spend &amp; runway</h2>
            </div>
            <div class="fs-3 fw-bold os-mono mb-1"><?= e(fmt_money($available, 2)) ?> <span class="fs-6 text-muted"><?= e($cur) ?></span></div>
            <div class="mb-3 text-muted">
                <?= $runwayDays === null ? 'Runway unknown (no spend today yet)' : ('≈ ' . e(number_format($runwayDays, 1)) . ' days at today’s pace') ?>
            </div>
            <div class="row g-2 small mb-3">
                <div class="col-6"><div class="text-muted">Today so far</div><strong class="os-mono"><?= e(fmt_money($t['spend'], 2)) ?></strong></div>
                <div class="col-6"><div class="text-muted">Yesterday by now</div><strong class="os-mono"><?= e(fmt_money($metrics['yesterday_pace'], 2)) ?></strong></div>
            </div>
            <?php if ($lowRunway): ?>
                <div class="os-pill os-pill-warn mb-2">Warning · low runway</div>
            <?php endif; ?>
            <a class="btn btn-primary btn-sm" href="<?= e(url('/ledger')) ?>">View ledger</a>
        </div>
    </div>

    <div class="os-dash-grid os-dash-bottom">
        <div class="os-widget">
            <div class="os-widget-h">
                <h2 class="os-widget-title">Daily usage · last 7 days</h2>
                <a class="small" href="<?= e(url('/reports/summary-day')) ?>">All usage</a>
            </div>
            <?php if (!$daily): ?>
                <div class="os-empty py-3"><div class="os-empty-title">No usage yet</div></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 os-table">
                        <thead><tr><th>Day</th><th class="os-num">Billed</th><th class="os-num">Attempted</th><th class="os-num">Answer %</th><th>Minutes</th><th class="os-num">Cost</th></tr></thead>
                        <tbody>
                        <?php foreach ($daily as $d): ?>
                            <tr>
                                <td class="os-mono"><?= e($d['day']) ?></td>
                                <td class="os-num"><?= e(number_format($d['answered'])) ?></td>
                                <td class="os-num"><?= e(number_format($d['attempts'])) ?></td>
                                <td class="os-num"><?= e($d['asr']) ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="os-mono"><?= e((string) $d['minutes']) ?></span>
                                        <div class="os-usage-bar flex-grow-1"><span style="width:<?= e((string) round(100 * $d['minutes'] / $maxMin)) ?>%"></span></div>
                                    </div>
                                </td>
                                <td class="os-num os-mono"><?= e(fmt_money($d['cost'], 2)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <div class="os-widget">
            <div class="os-widget-h"><h2 class="os-widget-title">Since today (UTC)</h2></div>
            <div class="row g-3">
                <div class="col-4">
                    <div class="os-section-label mb-1">Calls</div>
                    <div class="fs-3 fw-bold"><?= e(number_format($t['attempts'])) ?></div>
                </div>
                <div class="col-4">
                    <div class="os-section-label mb-1">Connected</div>
                    <div class="fs-3 fw-bold"><?= e(number_format($t['answered'])) ?></div>
                    <div class="small text-muted"><?= e($t['asr']) ?>%</div>
                </div>
                <div class="col-4">
                    <div class="os-section-label mb-1">Spent</div>
                    <div class="fs-3 fw-bold os-mono"><?= e(fmt_money($t['spend'], 2)) ?></div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

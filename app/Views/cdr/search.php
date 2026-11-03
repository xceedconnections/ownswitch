<?php
/** @var array $result @var array $f @var array $customers @var array $carriers @var list<string> $dispositions @var bool $showCost @var array $query @var bool $failedOnly */
$failedOnly = !empty($failedOnly);
$exportQs = '?' . http_build_query(array_filter($f, static fn ($v) => is_string($v) || is_int($v)));
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title"><?= $failedOnly ? 'Failed CDRs' : 'CDR Search' ?> <span class="os-count"><?= e($result['total']) ?></span></h1>
        <p class="os-page-sub"><?= $failedOnly ? 'Non-answered calls (busy, cancel, fail, no answer)' : 'Filter and export call detail records' ?></p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/cdrs/export' . $exportQs)) ?>"><i class="bi bi-download me-1"></i>Export CSV</a>
</div>

<form method="get" action="<?= e(url('/cdrs')) ?>" class="os-toolbar mb-3">
    <?php if ($failedOnly): ?><input type="hidden" name="failed_only" value="1"><?php endif; ?>
    <div class="row g-2">
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="from">From</label><input type="date" class="form-control form-control-sm" id="from" name="from" value="<?= e($f['from']) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="to">To</label><input type="date" class="form-control form-control-sm" id="to" name="to" value="<?= e($f['to']) ?>"></div>
        <?php if ($customers): ?>
            <div class="col-6 col-md-3"><label class="form-label small mb-0" for="customer_id">Customer</label>
                <select class="form-select form-select-sm" id="customer_id" name="customer_id"><option value="">All</option>
                    <?php foreach ($customers as $id => $n): ?><option value="<?= e($id) ?>"<?= (string) ($f['customer_id'] ?? '') === (string) $id ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
                </select></div>
        <?php endif; ?>
        <?php if ($carriers): ?>
            <div class="col-6 col-md-2"><label class="form-label small mb-0" for="carrier_id">Carrier</label>
                <select class="form-select form-select-sm" id="carrier_id" name="carrier_id"><option value="">All</option>
                    <?php foreach ($carriers as $id => $n): ?><option value="<?= e($id) ?>"<?= (string) ($f['carrier_id'] ?? '') === (string) $id ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
                </select></div>
        <?php endif; ?>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="src">CallerID</label><input class="form-control form-control-sm" id="src" name="src" value="<?= e($f['src'] ?? '') ?>" maxlength="64"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="dst">Destination prefix</label><input class="form-control form-control-sm" id="dst" name="dst" value="<?= e($f['dst'] ?? '') ?>" maxlength="30"></div>
        <?php if (!$failedOnly): ?>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="disposition">Disposition</label>
            <select class="form-select form-select-sm" id="disposition" name="disposition"><option value="">All</option>
                <?php foreach ($dispositions as $d): ?><option value="<?= e($d) ?>"<?= ($f['disposition'] ?? '') === $d ? ' selected' : '' ?>><?= e($d) ?></option><?php endforeach; ?>
            </select></div>
        <?php endif; ?>
        <div class="col-6 col-md-2"><label class="form-label small mb-0" for="min_duration">Min duration (s)</label><input class="form-control form-control-sm" id="min_duration" name="min_duration" value="<?= e($f['min_duration'] ?? '') ?>" inputmode="numeric" maxlength="7"></div>
        <div class="col-auto align-self-end"><button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel me-1"></i>Search</button></div>
    </div>
</form>

<div class="os-table-wrap">
<?php if ($result['total'] === 0): ?>
    <div class="os-empty"><div class="os-empty-title">No calls match</div><div>No CDRs in this period for the selected filters.</div></div>
<?php else: ?>
    <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 os-table">
        <thead><tr>
            <th>ID</th><th>Time (UTC)</th>
            <?php if ($customers): ?><th>Customer</th><?php endif; ?>
            <th>SIP</th><th>CallerID</th><th>Destination</th><th>Result</th><th>SIP code</th>
            <th class="os-num">Dur (s)</th><th class="os-num">Billable</th><th>I/S</th><th class="os-num">Charge</th>
            <?php if ($showCost): ?><th>Trunk</th><th class="os-num">Cost</th><th class="os-num">Margin</th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($result['rows'] as $c): ?>
            <tr>
                <td><a href="<?= e(url('/cdrs/' . (int) $c['id'])) ?>" class="os-mono"><?= e($c['id']) ?></a><?= !empty($c['is_demo']) ? ' <span class="badge text-bg-warning">DEMO</span>' : '' ?></td>
                <td class="os-mono"><?= e(substr((string) $c['setup_at'], 0, 19)) ?></td>
                <?php if ($customers): ?><td><?= e($c['customer_name'] ?? '—') ?></td><?php endif; ?>
                <td class="os-mono"><?= e($c['sip_username'] ?? '—') ?></td>
                <td class="os-mono"><?= e($c['src'] ?? '—') ?></td>
                <td class="os-mono"><?= e($c['dst_normalized']) ?></td>
                <td><span class="badge text-bg-<?= $c['disposition'] === 'ANSWERED' ? 'success' : 'secondary' ?>"><?= e($c['disposition']) ?></span></td>
                <td class="os-mono"><?= e($c['sip_response'] !== null && $c['sip_response'] !== '' ? $c['sip_response'] : '—') ?></td>
                <td class="os-num"><?= e($c['duration_actual']) ?></td>
                <td class="os-num"><?= e($c['duration_billable_customer']) ?></td>
                <td class="os-mono"><?= e($c['customer_initial'] ?? '—') ?>/<?= e($c['customer_subsequent'] ?? '—') ?></td>
                <td class="os-num os-mono"><?= e($c['customer_charge']) ?></td>
                <?php if ($showCost): ?>
                    <td><?= e($c['trunk_name'] ?? $c['carrier_name'] ?? '—') ?></td>
                    <td class="os-num os-mono"><?= e($c['carrier_cost']) ?></td>
                    <td class="os-num os-mono"><?= e($c['gross_margin']) ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php include __DIR__ . '/../partials/pagination.php'; ?>
<?php endif; ?>
</div>

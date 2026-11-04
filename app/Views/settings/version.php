<?php
/** @var array $status */
$s = $status;
$upd = !empty($s['update_available']);
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Version</h1>
        <p class="os-page-sub">Installed Ownswitch build vs GitHub <code><?= e((string) ($s['repo'] ?? 'ownswitch')) ?></code> / <?= e((string) ($s['branch'] ?? 'main')) ?></p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/settings')) ?>"><i class="bi bi-gear me-1"></i>Settings</a>
</div>

<?php if (($s['detail'] ?? '') !== '' && ($s['detail'] ?? '') !== 'ok'): ?>
    <div class="alert alert-warning"><?= e((string) $s['detail']) ?></div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="os-panel p-3 h-100">
            <h2 class="h6 text-muted">Installed</h2>
            <div class="os-mono fs-5 mb-1"><?= e((string) ($s['current_short'] ?: '—')) ?></div>
            <?php if (($s['current_sha'] ?? '') !== ''): ?>
                <div class="small text-muted os-mono text-break"><?= e((string) $s['current_sha']) ?></div>
            <?php endif; ?>
            <?php if (($s['current_date'] ?? '') !== ''): ?>
                <div class="small mt-2"><?= e((string) $s['current_date']) ?></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-md-6">
        <div class="os-panel p-3 h-100">
            <h2 class="h6 text-muted">GitHub main</h2>
            <div class="os-mono fs-5 mb-1"><?= e((string) ($s['remote_short'] ?: '—')) ?></div>
            <?php if (($s['remote_sha'] ?? '') !== ''): ?>
                <div class="small text-muted os-mono text-break"><?= e((string) $s['remote_sha']) ?></div>
            <?php endif; ?>
            <?php if (($s['remote_message'] ?? '') !== ''): ?>
                <div class="mt-2"><?= e((string) $s['remote_message']) ?></div>
            <?php endif; ?>
            <?php if (($s['remote_date'] ?? '') !== ''): ?>
                <div class="small text-muted mt-1"><?= e((string) $s['remote_date']) ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="os-panel p-3">
    <?php if ($upd): ?>
        <div class="alert alert-info mb-3 mb-md-4">
            <strong>New update available</strong> on GitHub. Click Update now to pull and deploy (same as <code>refresh-ui.sh</code>).
        </div>
        <form method="post" action="<?= e(url('/settings/version/update')) ?>" data-confirm="Update Ownswitch from GitHub now? This reloads the portal UI and may briefly interrupt the admin session.">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary" data-once<?= empty($s['helper']) ? ' disabled' : '' ?>>
                <i class="bi bi-cloud-download me-1"></i>Update now
            </button>
        </form>
        <?php if (empty($s['helper'])): ?>
            <p class="form-text text-danger mt-2 mb-0">Update helper not installed. On the server run: <code>sudo bash /opt/ownswitch-src/deploy/refresh-ui.sh</code> once, then reopen this page.</p>
        <?php endif; ?>
    <?php else: ?>
        <p class="mb-2">You are on the latest GitHub <code>main</code> commit<?= ($s['current_short'] ?? '') !== '' ? ' (' . e((string) $s['current_short']) . ')' : '' ?>.</p>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/settings/version')) ?>"><i class="bi bi-arrow-clockwise me-1"></i>Check again</a>
    <?php endif; ?>
</div>

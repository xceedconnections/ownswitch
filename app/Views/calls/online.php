<?php /** @var list<array> $calls @var ?string $error @var int $count */ ?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Calls Online <span class="os-count"><?= e((string) $count) ?></span></h1>
        <p class="os-page-sub">Live dialogs from OpenSIPS · auto-refresh every 5s</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/calls-online')) ?>"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-warning"><?= e($error) ?></div>
<?php endif; ?>

<div class="os-table-wrap">
<?php if ($calls === []): ?>
    <div class="os-empty"><div class="os-empty-title">No active calls</div><div>When INVITEs create dialogs they appear here.</div></div>
<?php else: ?>
    <div class="table-responsive"><table class="table table-sm mb-0 os-table">
        <thead><tr><th>From</th><th>To</th><th>State</th><th class="os-num">Duration</th><th>Call-ID</th></tr></thead>
        <tbody>
        <?php foreach ($calls as $c): ?>
            <tr>
                <td class="os-mono"><?= e($c['from_user'] !== '' ? $c['from_user'] : $c['from']) ?></td>
                <td class="os-mono"><?= e($c['to_user'] !== '' ? $c['to_user'] : $c['to']) ?></td>
                <td><?= e($c['state']) ?></td>
                <td class="os-num os-mono"><?= e(fmt_duration((int) $c['duration'])) ?></td>
                <td class="os-mono small text-muted"><?= e(mb_substr((string) $c['callid'], 0, 40)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
</div>
<meta http-equiv="refresh" content="5">

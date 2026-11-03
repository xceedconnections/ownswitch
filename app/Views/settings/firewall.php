<?php
/** @var array $status @var bool $available @var string $helperPath */
$s = $status;
$available = !empty($available);
$banned = $s['banned'] ?? [];
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Firewall</h1>
        <p class="os-page-sub">SIP fail2ban — block IPs after wrong password attempts on port 5060</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/settings')) ?>"><i class="bi bi-gear me-1"></i>Settings</a>
</div>

<?php if (empty($s['ok'])): ?>
    <div class="alert alert-warning">
        <?= e((string) ($s['detail'] ?? 'fail2ban not available')) ?>
    </div>
<?php else: ?>
    <div class="os-dash-grid os-dash-kpis mb-3">
        <div class="os-kpi"><div class="lbl">Jail</div><div class="val"><?= !empty($s['enabled']) ? 'On' : 'Off' ?></div><div class="sub">opensips-auth</div></div>
        <div class="os-kpi"><div class="lbl">Banned now</div><div class="val"><?= e((string) ($s['currently_banned'] ?? 0)) ?></div><div class="sub">active blocks</div></div>
        <div class="os-kpi"><div class="lbl">Threshold</div><div class="val"><?= e((string) ($s['maxretry'] ?? '5')) ?></div><div class="sub">fails / <?= e((string) ($s['findtime'] ?? '10m')) ?></div></div>
        <div class="os-kpi"><div class="lbl">Ban time</div><div class="val"><?= e((string) ($s['bantime'] ?? '1h')) ?></div><div class="sub">UDP/TCP <?= e((string) ($s['sip_port'] ?? '5060')) ?></div></div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="os-panel p-3">
            <h2 class="h6 mb-3">Ban thresholds</h2>
            <form method="post" action="<?= e(url('/settings/firewall/config')) ?>">
                <?= csrf_field() ?>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="jail_enabled" id="jail_enabled" value="1"<?= (($s['jail_enabled'] ?? 'true') === 'true' || ($s['jail_enabled'] ?? '') === '1') ? ' checked' : '' ?><?= !$available ? ' disabled' : '' ?>>
                    <label class="form-check-label" for="jail_enabled">Enable SIP auth jail</label>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="maxretry">Wrong attempts before ban</label>
                    <input class="form-control form-control-sm" type="number" min="1" max="100" id="maxretry" name="maxretry" value="<?= e((string) ($s['maxretry'] ?? '5')) ?>" required<?= !$available ? ' disabled' : '' ?>>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="findtime">Window (findtime)</label>
                    <input class="form-control form-control-sm" id="findtime" name="findtime" value="<?= e((string) ($s['findtime'] ?? '10m')) ?>" required<?= !$available ? ' disabled' : '' ?>>
                    <div class="form-text">Examples: 10m, 1h</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="bantime">Ban duration</label>
                    <input class="form-control form-control-sm" id="bantime" name="bantime" value="<?= e((string) ($s['bantime'] ?? '1h')) ?>" required<?= !$available ? ' disabled' : '' ?>>
                    <div class="form-text">Examples: 1h, 24h, 1d</div>
                </div>
                <button type="submit" class="btn btn-primary btn-sm" data-once<?= !$available ? ' disabled' : '' ?>>Save</button>
            </form>
            <p class="small text-muted mt-3 mb-0">Counts password failures on REGISTER and INVITE only. IP-auth trunks (address table) are not counted.</p>
        </div>

        <div class="os-panel p-3 mt-3">
            <h2 class="h6 mb-3">Manual ban</h2>
            <form method="post" action="<?= e(url('/settings/firewall/ban')) ?>" class="row g-2 align-items-end">
                <?= csrf_field() ?>
                <div class="col">
                    <label class="form-label" for="ban_ip">IP address</label>
                    <input class="form-control form-control-sm" id="ban_ip" name="ip" placeholder="1.2.3.4" required pattern="^[0-9a-fA-F:.]+$"<?= !$available ? ' disabled' : '' ?>>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Ban this IP from SIP?" data-once<?= !$available ? ' disabled' : '' ?>>Ban</button>
                </div>
            </form>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="os-panel overflow-hidden">
            <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Currently banned</span>
                <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/settings/firewall')) ?>"><i class="bi bi-arrow-clockwise"></i></a>
            </div>
            <?php if ($banned === []): ?>
                <div class="os-empty py-4"><div class="os-empty-title">No banned IPs</div><div>Attackers hitting wrong SIP passwords will appear here after <?= e((string) ($s['maxretry'] ?? '5')) ?> failures.</div></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 os-table">
                        <thead><tr><th>IP</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($banned as $ip): ?>
                            <tr>
                                <td class="os-mono"><?= e($ip) ?></td>
                                <td class="text-end">
                                    <form method="post" action="<?= e(url('/settings/firewall/unban')) ?>" class="d-inline" data-confirm="Unban <?= e($ip) ?>?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="ip" value="<?= e($ip) ?>">
                                        <button type="submit" class="btn btn-outline-primary btn-sm" data-once>Unban</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <p class="small text-muted mt-2 mb-0">Helper: <code><?= e($helperPath) ?></code> · Also protects SSH via the installer fail2ban jail.</p>
    </div>
</div>

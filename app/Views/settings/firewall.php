<?php
/** @var array $status @var bool $available @var string $helperPath */
$s = $status;
$available = !empty($available);
$banned = $s['banned'] ?? [];
$details = $s['ban_details'] ?? [];
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
        <div class="os-kpi os-kpi--teal"><div class="lbl">Jail</div><div class="val"><?= !empty($s['enabled']) ? 'On' : 'Off' ?></div><div class="sub">opensips-auth</div></div>
        <div class="os-kpi os-kpi--amber"><div class="lbl">Banned now</div><div class="val"><?= e((string) ($s['currently_banned'] ?? 0)) ?></div><div class="sub">active blocks</div></div>
        <div class="os-kpi os-kpi--blue"><div class="lbl">Threshold</div><div class="val"><?= e((string) ($s['maxretry'] ?? '5')) ?></div><div class="sub">fails / <?= e((string) ($s['findtime'] ?? '10m')) ?></div></div>
        <div class="os-kpi os-kpi--violet"><div class="lbl">Ban time</div><div class="val"><?= e((string) ($s['bantime'] ?? '1h')) ?></div><div class="sub">auto-unban · UDP/TCP <?= e((string) ($s['sip_port'] ?? '5060')) ?></div></div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-4">
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
                    <div class="form-text">e.g. 10m, 1h</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="bantime">Ban duration</label>
                    <input class="form-control form-control-sm" id="bantime" name="bantime" value="<?= e((string) ($s['bantime'] ?? '1h')) ?>" required<?= !$available ? ' disabled' : '' ?>>
                    <div class="form-text">e.g. 30m, 1h, 24h — IP is <strong>automatically unbanned</strong> after this time. Saving reapplies the timer to currently banned IPs.</div>
                </div>
                <button type="submit" class="btn btn-primary btn-sm" data-once<?= !$available ? ' disabled' : '' ?>>Save</button>
            </form>
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
            <p class="form-text mb-0 mt-2">Manual bans also expire after the ban duration above.</p>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="os-panel overflow-hidden">
            <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Currently banned <span class="text-muted fw-normal">(<?= e((string) count($banned)) ?>)</span></span>
                <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/settings/firewall')) ?>"><i class="bi bi-arrow-clockwise"></i></a>
            </div>
            <?php if ($banned === []): ?>
                <div class="os-empty py-4"><div class="os-empty-title">No banned IPs</div><div>Attackers with wrong SIP passwords appear here after <?= e((string) ($s['maxretry'] ?? '5')) ?> failures.</div></div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($banned as $i => $ip):
                        $d = $details[$ip] ?? ['country' => '—', 'city' => '', 'isp' => '', 'reason' => '—', 'attempts' => []];
                        $cid = 'ban-detail-' . preg_replace('/[^a-zA-Z0-9]/', '', $ip) . '-' . $i;
                        $attempts = $d['attempts'] ?? [];
                        ?>
                        <div class="list-group-item px-3 py-3">
                            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-start">
                                <div class="me-auto" style="min-width:12rem">
                                    <div class="os-mono fw-semibold"><?= e($ip) ?></div>
                                    <?php if (($d['isp'] ?? '') !== ''): ?>
                                        <div class="small text-muted"><?= e((string) $d['isp']) ?></div>
                                    <?php endif; ?>
                                    <div class="small mt-1">
                                        <?= e((string) ($d['country'] ?? '—')) ?>
                                        <?php if (($d['city'] ?? '') !== ''): ?>
                                            <span class="text-muted">· <?= e((string) $d['city']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mt-1"><?= e((string) ($d['reason'] ?? '—')) ?></div>
                                    <?php if ($attempts !== []): ?>
                                        <button class="btn btn-link btn-sm px-0 mt-1" type="button" data-bs-toggle="collapse" data-bs-target="#<?= e($cid) ?>" aria-expanded="false">
                                            Show more details
                                        </button>
                                    <?php else: ?>
                                        <div class="small text-muted mt-1">No journal lines captured</div>
                                    <?php endif; ?>
                                </div>
                                <form method="post" action="<?= e(url('/settings/firewall/unban')) ?>" class="flex-shrink-0" data-confirm="Unban <?= e($ip) ?>?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="ip" value="<?= e($ip) ?>">
                                    <button type="submit" class="btn btn-outline-primary btn-sm" data-once>Unban</button>
                                </form>
                            </div>
                            <?php if ($attempts !== []): ?>
                                <div class="collapse mt-2" id="<?= e($cid) ?>">
                                    <div class="border rounded bg-light p-2">
                                        <div class="small text-muted mb-1">Recent auth failures</div>
                                        <ul class="mb-0 ps-3" style="max-height:10rem;overflow:auto">
                                            <?php foreach ($attempts as $line): ?>
                                                <li class="os-mono" style="font-size:.72rem;word-break:break-all"><?= e($line) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <p class="small text-muted mt-2 mb-0">Helper: <code><?= e($helperPath) ?></code> · Ban duration <?= e((string) ($s['bantime'] ?? '1h')) ?> then auto-unban</p>
    </div>
</div>

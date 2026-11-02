<?php
/** @var string $content @var string $title @var array $flashes @var array $menu */
$user = current_user();
$roleLabels = [
    'super_admin' => 'Super Admin', 'admin' => 'Admin', 'reseller' => 'Reseller',
    'customer_owner' => 'Customer', 'customer_tech' => 'Customer Tech', 'reporter' => 'Reporter',
];
$icons = [
    '/' => 'bi-speedometer2',
    '/customers' => 'bi-people',
    '/resellers' => 'bi-diagram-3',
    '/sip-users' => 'bi-person-badge',
    '/ip-auth' => 'bi-shield-lock',
    '/rate-plans' => 'bi-tags',
    '/prefixes' => 'bi-hash',
    '/tariffs' => 'bi-currency-dollar',
    '/carrier-tariffs' => 'bi-cash-stack',
    '/tariffs/import' => 'bi-filetype-csv',
    '/carriers' => 'bi-hdd-network',
    '/trunks' => 'bi-ethernet',
    '/routing/plans' => 'bi-collection',
    '/routing' => 'bi-signpost',
    '/routing/simulate' => 'bi-compass',
    '/cdrs' => 'bi-telephone-forward',
    '/reports/asr-acd' => 'bi-graph-up',
    '/reports/financial' => 'bi-pie-chart',
    '/billing/preview' => 'bi-calculator',
    '/billing/topup' => 'bi-wallet2',
    '/ledger' => 'bi-journal-text',
    '/invoices' => 'bi-receipt',
    '/recordings' => 'bi-mic',
    '/fraud' => 'bi-exclamation-octagon',
    '/system/status' => 'bi-activity',
    '/settings' => 'bi-gear',
];
$initials = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $a = strtoupper(substr($parts[0] ?? 'U', 0, 1));
    $b = strtoupper(substr($parts[1] ?? '', 0, 1));
    return $a . $b;
};
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e($title) ?> · Ownswitch</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" integrity="sha384-tViUnnbYAV00FLIhhi3v/dWt3Jxw4gZQcNoSCxCIFNJVCx7/D55/wXsrNIRANwdD" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="os-body<?= $user ? '' : ' os-auth-body' ?>">
<?php if ($user): ?>
<nav class="navbar os-topbar px-3">
    <div class="d-flex align-items-center gap-2">
        <button class="btn btn-sm btn-outline-light d-lg-none border-0" type="button" data-bs-toggle="offcanvas" data-bs-target="#osSidebar" aria-controls="osSidebar" aria-label="Open menu">
            <i class="bi bi-list fs-5"></i>
        </button>
        <a class="os-brand" href="<?= e(url('/')) ?>">
            <span class="os-brand-mark" aria-hidden="true">Ow</span>
            <span>Ownswitch</span>
        </a>
        <span class="os-topbar-meta d-none d-md-inline ms-2">VoIP billing · routing · CDR</span>
    </div>
    <div class="dropdown">
        <button class="btn os-user-btn dropdown-toggle d-inline-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="os-avatar"><?= e($initials((string) $user['name'])) ?></span>
            <span class="d-none d-sm-inline"><?= e($user['name']) ?></span>
            <span class="badge os-role-pill d-none d-md-inline-flex"><?= e($roleLabels[$user['role']] ?? $user['role']) ?></span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2">
            <li class="px-3 py-2 small text-muted"><?= e((string) $user['email']) ?></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= e(url('/account/password')) ?>"><i class="bi bi-key me-2"></i>Change password</a></li>
            <li>
                <form method="post" action="<?= e(url('/logout')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Sign out</button>
                </form>
            </li>
        </ul>
    </div>
</nav>

<div class="os-shell">
    <aside class="offcanvas-lg offcanvas-start os-sidebar text-bg-dark" tabindex="-1" id="osSidebar" aria-label="Main navigation">
        <div class="offcanvas-header d-lg-none border-bottom border-secondary-subtle">
            <span class="os-brand"><span class="os-brand-mark">Ow</span> Menu</span>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#osSidebar" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body d-flex flex-column p-0">
            <nav class="flex-grow-1" aria-label="Sections">
                <?php foreach ($menu as $section): ?>
                    <div class="os-nav-section"><?= e($section['label']) ?></div>
                    <ul class="nav flex-column mb-1">
                        <?php foreach ($section['items'] as $item):
                            $icon = $icons[$item['path']] ?? 'bi-circle';
                            ?>
                            <li class="nav-item">
                                <a class="nav-link os-nav-link<?= nav_active($item['path']) ?>" href="<?= e(url($item['path'])) ?>">
                                    <i class="bi <?= e($icon) ?>" aria-hidden="true"></i>
                                    <span><?= e($item['label']) ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>
            </nav>
            <div class="os-sidebar-foot d-none d-lg-block">
                Ownswitch CRM · secure session
            </div>
        </div>
    </aside>
    <main class="os-main">
        <?php foreach ($flashes as $f): ?>
            <div class="alert alert-<?= e(in_array($f['type'], ['success', 'danger', 'warning', 'info'], true) ? $f['type'] : 'info') ?> alert-dismissible fade show" role="alert">
                <?= e($f['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endforeach; ?>
        <?= $content ?>
    </main>
</div>
<?php else: ?>
<main class="os-auth-page">
    <div class="os-auth-wrap">
        <?php foreach ($flashes as $f): ?>
            <div class="alert alert-<?= e(in_array($f['type'], ['success', 'danger', 'warning', 'info'], true) ? $f['type'] : 'info') ?>" role="alert"><?= e($f['message']) ?></div>
        <?php endforeach; ?>
        <?= $content ?>
        <p class="os-auth-foot mb-0">Multi-tenant VoIP billing · OpenSIPS · CGRateS</p>
    </div>
</main>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>

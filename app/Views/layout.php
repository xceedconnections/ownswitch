<?php
/** @var string $content @var string $title @var array $flashes @var array $menu */
$user = current_user();
$roleLabels = ['super_admin' => 'Super Admin', 'admin' => 'Admin', 'reseller' => 'Reseller', 'customer_owner' => 'Customer', 'customer_tech' => 'Customer (tech)', 'reporter' => 'Reporter'];
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e($title) ?> - Ownswitch</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="os-body">
<?php if ($user): ?>
<nav class="navbar navbar-dark bg-dark os-topbar px-3">
    <div class="d-flex align-items-center gap-2">
        <button class="btn btn-outline-light btn-sm d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#osSidebar" aria-controls="osSidebar" aria-label="Menu">&#9776;</button>
        <a class="navbar-brand fw-semibold mb-0" href="<?= e(url('/')) ?>">Ownswitch</a>
    </div>
    <div class="dropdown">
        <button class="btn btn-sm btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <?= e($user['name']) ?> <span class="badge text-bg-secondary"><?= e($roleLabels[$user['role']] ?? $user['role']) ?></span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="<?= e(url('/account/password')) ?>">Change password</a></li>
            <li><hr class="dropdown-divider"></li>
            <li>
                <form method="post" action="<?= e(url('/logout')) ?>" class="px-3">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-link p-0 text-decoration-none">Sign out</button>
                </form>
            </li>
        </ul>
    </div>
</nav>

<div class="os-shell">
    <aside class="offcanvas-lg offcanvas-start os-sidebar bg-dark" tabindex="-1" id="osSidebar" aria-label="Main navigation">
        <div class="offcanvas-header d-lg-none">
            <span class="text-white fw-semibold">Menu</span>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#osSidebar" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body flex-column p-0">
            <?php foreach ($menu as $section): ?>
                <div class="os-nav-section"><?= e($section['label']) ?></div>
                <ul class="nav flex-column">
                    <?php foreach ($section['items'] as $item): ?>
                        <li class="nav-item">
                            <a class="nav-link os-nav-link<?= nav_active($item['path']) ?>" href="<?= e(url($item['path'])) ?>"><?= e($item['label']) ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </div>
    </aside>
    <main class="os-main p-3 p-lg-4">
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
<main class="container os-auth">
    <?php foreach ($flashes as $f): ?>
        <div class="alert alert-<?= e(in_array($f['type'], ['success', 'danger', 'warning', 'info'], true) ? $f['type'] : 'info') ?>" role="alert"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
</main>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>

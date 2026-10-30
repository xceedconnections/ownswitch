<?php /** @var ?string $error @var string $email */ ?>
<div class="text-center mb-4">
    <h1 class="h3 fw-semibold mb-1">Ownswitch</h1>
    <div class="text-muted">VoIP billing &amp; routing portal</div>
</div>
<div class="card shadow-sm">
    <div class="card-body p-4">
        <h2 class="h5 mb-3">Sign in</h2>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2" role="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/login')) ?>" autocomplete="on">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label for="email" class="form-label">Email</label>
                <input type="email" class="form-control" id="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="username" maxlength="190">
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password" maxlength="256">
            </div>
            <button type="submit" class="btn btn-primary w-100" data-once>Sign in</button>
        </form>
    </div>
</div>
<p class="text-center text-muted small mt-3">First time? Create an admin with <code>php bin/create_admin.php</code></p>

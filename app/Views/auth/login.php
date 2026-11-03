<?php /** @var ?string $error @var string $email @var string $login */ ?>
<?php $loginVal = $login ?? $email ?? ''; ?>
<div class="os-auth-card">
    <div class="os-auth-brand">
        <div class="os-brand-mark" aria-hidden="true">Ow</div>
        <h1>Ownswitch</h1>
        <p>Sign in with username or email + portal password</p>
    </div>
    <?php if ($error): ?>
        <div class="alert alert-danger py-2" role="alert"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/login')) ?>" autocomplete="on">
        <?= csrf_field() ?>
        <div class="mb-3">
            <label for="login" class="form-label fw-semibold">Username or email</label>
            <input type="text" class="form-control form-control-lg" id="login" name="login" value="<?= e($loginVal) ?>" required autofocus autocomplete="username" maxlength="190" placeholder="admin or you@company.com">
            <div class="form-text">Use the Username from Users, or Email (portal login)</div>
        </div>
        <div class="mb-3">
            <label for="password" class="form-label fw-semibold">Password</label>
            <input type="password" class="form-control form-control-lg" id="password" name="password" required autocomplete="current-password" maxlength="256" placeholder="••••••••••••">
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-100" data-once>Sign in</button>
    </form>
</div>

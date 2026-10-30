<?php /** @var list<string> $errors */ ?>
<h1 class="h4 mb-3">Change password</h1>
<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger py-2"><?= e($err) ?></div>
<?php endforeach; ?>
<form method="post" action="<?= e(url('/account/password')) ?>" class="card card-body col-lg-5">
    <?= csrf_field() ?>
    <div class="mb-3"><label class="form-label" for="current">Current password</label>
        <input type="password" class="form-control" id="current" name="current" required autocomplete="current-password"></div>
    <div class="mb-3"><label class="form-label" for="new">New password</label>
        <input type="password" class="form-control" id="new" name="new" required autocomplete="new-password" minlength="12">
        <div class="form-text">At least 12 characters with upper-case, lower-case and a digit.</div></div>
    <div class="mb-3"><label class="form-label" for="confirm">Confirm new password</label>
        <input type="password" class="form-control" id="confirm" name="confirm" required autocomplete="new-password" minlength="12"></div>
    <button class="btn btn-primary" type="submit" data-once>Update password</button>
</form>

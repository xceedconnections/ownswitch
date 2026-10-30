<?php /** @var array $schema @var array $values @var array<string,string> $errors */ ?>
<h1 class="h4 mb-3">Settings</h1>
<form method="post" action="<?= e(url('/settings')) ?>" class="card shadow-sm col-xl-8">
    <div class="card-body">
        <?= csrf_field() ?>
        <?php foreach ($schema as $key => [$label, $type, $help]):
            $err = $errors[$key] ?? null; $val = $values[$key] ?? ''; ?>
            <div class="mb-3">
                <?php if ($type === 'bool'): ?>
                    <div class="form-check"><input class="form-check-input" type="checkbox" id="<?= e($key) ?>" name="<?= e($key) ?>" value="1"<?= in_array($val, ['1', 'true'], true) ? ' checked' : '' ?>>
                        <label class="form-check-label" for="<?= e($key) ?>"><?= e($label) ?></label></div>
                <?php elseif ($type === 'media'): ?>
                    <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                    <select class="form-select" id="<?= e($key) ?>" name="<?= e($key) ?>"><?php foreach (['direct', 'relay', 'record'] as $m): ?><option value="<?= e($m) ?>"<?= $val === $m ? ' selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?></select>
                <?php else: ?>
                    <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                    <input class="form-control<?= $err ? ' is-invalid' : '' ?>" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($val) ?>" maxlength="20">
                <?php endif; ?>
                <?php if ($err): ?><div class="invalid-feedback d-block"><?= e($err) ?></div><?php endif; ?>
                <?php if ($help): ?><div class="form-text"><?= e($help) ?></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="card-footer"><button class="btn btn-primary" type="submit" data-once>Save settings</button></div>
</form>

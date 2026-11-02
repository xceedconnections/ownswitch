<?php /** @var array $schema @var array $values @var array<string,string> $errors */ ?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title">Settings</h1>
        <p class="os-page-sub">Tenant configuration</p>
    </div>
</div>
<form method="post" action="<?= e(url('/settings')) ?>" class="os-panel col-xl-8">
    <div class="p-3 p-md-4">
        <?= csrf_field() ?>
        <?php foreach ($schema as $key => [$label, $type, $help]):
            $err = $errors[$key] ?? null; $val = $values[$key] ?? ''; ?>
            <div class="mb-3">
                <?php if ($type === 'bool'): ?>
                    <div class="form-check"><input class="form-check-input" type="checkbox" id="<?= e($key) ?>" name="<?= e($key) ?>" value="1"<?= in_array($val, ['1', 'true'], true) ? ' checked' : '' ?>>
                        <label class="form-check-label" for="<?= e($key) ?>"><?= e($label) ?></label></div>
                <?php elseif ($type === 'media'): ?>
                    <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                    <select class="form-select" id="<?= e($key) ?>" name="<?= e($key) ?>">
                        <option value="relay"<?= $val === 'relay' ? ' selected' : '' ?>>Relay — media via this switch (RTPengine)</option>
                        <option value="direct"<?= $val === 'direct' ? ' selected' : '' ?>>Direct — media peer-to-peer (no switch media IP)</option>
                        <option value="record"<?= $val === 'record' ? ' selected' : '' ?>>Record — relay + call recording</option>
                    </select>
                <?php else: ?>
                    <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                    <input class="form-control<?= $err ? ' is-invalid' : '' ?>" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($val) ?>" maxlength="20">
                <?php endif; ?>
                <?php if ($err): ?><div class="invalid-feedback d-block"><?= e($err) ?></div><?php endif; ?>
                <?php if ($help): ?><div class="form-text"><?= e($help) ?></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="border-top px-3 px-md-4 py-3 bg-light rounded-bottom"><button class="btn btn-primary" type="submit" data-once><i class="bi bi-check2 me-1"></i>Save settings</button></div>
</form>

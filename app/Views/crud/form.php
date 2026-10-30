<?php
/**
 * @var string $title @var string $base @var string $singular @var list<array<string,mixed>> $fields
 * @var array $values @var array<string,string> $errors @var ?array $existing
 */
$action = $existing ? $base . '/' . (int) $existing['id'] : $base;
$isEdit = $existing !== null;
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><?= e($title) ?></h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url($base)) ?>">&larr; Back</a>
</div>

<?php if (!empty($errors['_form'])): ?>
    <div class="alert alert-danger"><?= e($errors['_form']) ?></div>
<?php endif; ?>

<form method="post" action="<?= e(url($action)) ?>" class="card shadow-sm col-xl-8" novalidate>
    <div class="card-body">
        <?= csrf_field() ?>
        <?php foreach ($fields as $f):
            $name = (string) $f['name'];
            $type = (string) ($f['type'] ?? 'text');
            $readonly = $isEdit && !empty($f['readonly_on_edit']);
            $val = array_key_exists($name, $values) ? $values[$name] : ($f['default'] ?? '');
            if ($type === 'password') {
                $val = '';   // never echo secrets back
            }
            $id = 'f_' . $name;
            $err = $errors[$name] ?? null;
            $cls = 'form-control' . ($err ? ' is-invalid' : '');
            ?>
            <div class="mb-3">
                <?php if ($type === 'checkbox'): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="<?= e($id) ?>" name="<?= e($name) ?>" value="1"<?= !empty($val) ? ' checked' : '' ?>>
                        <label class="form-check-label" for="<?= e($id) ?>"><?= e($f['label']) ?></label>
                    </div>
                <?php else: ?>
                    <label class="form-label" for="<?= e($id) ?>"><?= e($f['label']) ?><?= !empty($f['required']) ? ' <span class="text-danger">*</span>' : '' ?></label>
                    <?php if ($type === 'select'): ?>
                        <select class="form-select<?= $err ? ' is-invalid' : '' ?>" id="<?= e($id) ?>" name="<?= e($name) ?>"<?= $readonly ? ' disabled' : '' ?>>
                            <?php if (empty($f['required']) && !isset($f['options'][''])): ?><option value="">-</option><?php endif; ?>
                            <?php foreach ($f['options'] as $k => $label): ?>
                                <option value="<?= e($k) ?>"<?= ((string) $val === (string) $k) ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($type === 'textarea'): ?>
                        <textarea class="<?= e($cls) ?>" id="<?= e($id) ?>" name="<?= e($name) ?>" rows="3"<?= isset($f['max']) ? ' maxlength="' . (int) $f['max'] . '"' : '' ?>><?= e($val) ?></textarea>
                    <?php else:
                        $htmlType = match ($type) { 'email' => 'email', 'password' => 'password', default => 'text' };
                        $inputmode = in_array($type, ['int', 'prefix'], true) ? 'numeric' : ($type === 'number' ? 'decimal' : 'text');
                        ?>
                        <input class="<?= e($cls) ?>" type="<?= e($htmlType) ?>" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e($val) ?>"
                               inputmode="<?= e($inputmode) ?>"<?= isset($f['max']) && !in_array($type, ['int', 'number'], true) ? ' maxlength="' . (int) $f['max'] . '"' : '' ?>
                               <?= $readonly ? ' readonly' : '' ?><?= $type === 'password' ? ' autocomplete="new-password"' : '' ?>>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($err): ?><div class="invalid-feedback d-block"><?= e($err) ?></div><?php endif; ?>
                <?php if (!empty($f['help'])): ?><div class="form-text"><?= e($f['help']) ?></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="card-footer d-flex gap-2">
        <button type="submit" class="btn btn-primary" data-once>Save</button>
        <a class="btn btn-outline-secondary" href="<?= e(url($base)) ?>">Cancel</a>
    </div>
</form>

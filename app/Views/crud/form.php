<?php
/**
 * @var string $title @var string $base @var string $singular @var list<array<string,mixed>> $fields
 * @var array $values @var array<string,string> $errors @var ?array $existing
 */
$action = $existing ? $base . '/' . (int) $existing['id'] : $base;
$isEdit = $existing !== null;
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title"><?= e($title) ?></h1>
        <p class="os-page-sub"><?= $isEdit ? 'Update existing record' : 'Create a new ' . e(strtolower($singular)) ?></p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url($base)) ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<?php if (!empty($errors['_form'])): ?>
    <div class="alert alert-danger"><?= e($errors['_form']) ?></div>
<?php endif; ?>

<form method="post" action="<?= e(url($action)) ?>" class="os-panel col-xl-8" novalidate>
    <div class="p-3 p-md-4">
        <?= csrf_field() ?>
        <div class="row g-3">
        <?php foreach ($fields as $f):
            $name = (string) $f['name'];
            $type = (string) ($f['type'] ?? 'text');
            $readonly = $isEdit && !empty($f['readonly_on_edit']);
            $val = array_key_exists($name, $values) ? $values[$name] : ($f['default'] ?? '');
            if ($type === 'password') {
                $val = '';
            }
            $id = 'f_' . $name;
            $err = $errors[$name] ?? null;
            $cls = 'form-control' . ($err ? ' is-invalid' : '');
            $wide = in_array($type, ['textarea', 'password', 'multiselect'], true) || !empty($f['wide']);
            ?>
            <div class="<?= $wide ? 'col-12' : 'col-12 col-md-6' ?>">
                <?php if ($type === 'checkbox'): ?>
                    <div class="form-check mt-md-4">
                        <input class="form-check-input" type="checkbox" id="<?= e($id) ?>" name="<?= e($name) ?>" value="1"<?= !empty($val) ? ' checked' : '' ?>>
                        <label class="form-check-label" for="<?= e($id) ?>"><?= e($f['label']) ?></label>
                    </div>
                <?php else: ?>
                    <label class="form-label fw-semibold" for="<?= e($id) ?>"><?= e($f['label']) ?><?= !empty($f['required']) ? ' <span class="text-danger">*</span>' : '' ?></label>
                    <?php if ($type === 'select'): ?>
                        <select class="form-select<?= $err ? ' is-invalid' : '' ?>" id="<?= e($id) ?>" name="<?= e($name) ?>"<?= $readonly ? ' disabled' : '' ?>>
                            <?php if (empty($f['required']) && !isset($f['options'][''])): ?><option value="">—</option><?php endif; ?>
                            <?php foreach ($f['options'] as $k => $label): ?>
                                <option value="<?= e($k) ?>"<?= ((string) $val === (string) $k) ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($type === 'multiselect'):
                        $opts = $f['options'] ?? [];
                        $selected = [];
                        if (is_array($val)) {
                            $selected = array_values(array_filter(array_map('strval', $val)));
                        } else {
                            $selected = array_values(array_filter(array_map('trim', explode(',', (string) $val)), static fn ($x) => $x !== ''));
                        }
                        $selected = array_values(array_filter($selected, static fn ($c) => isset($opts[$c])));
                        ?>
                        <div class="os-ms<?= $err ? ' is-invalid' : '' ?>" data-os-multiselect data-name="<?= e($name) ?>"<?= $readonly ? ' data-readonly="1"' : '' ?>>
                            <input type="hidden" name="<?= e($name) ?>" id="<?= e($id) ?>" value="<?= e(implode(',', $selected)) ?>">
                            <div class="os-ms-chips" data-chips>
                                <?php foreach ($selected as $code): ?>
                                    <span class="os-ms-chip" data-value="<?= e($code) ?>">
                                        <span class="os-ms-chip-label"><?= e($opts[$code] ?? $code) ?></span>
                                        <?php if (!$readonly): ?>
                                            <button type="button" class="os-ms-chip-x" data-remove aria-label="Remove <?= e($code) ?>">&times;</button>
                                        <?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                            <?php if (!$readonly): ?>
                                <select class="form-select form-select-sm os-ms-add" data-add aria-label="Add <?= e($f['label']) ?>">
                                    <option value="">+ Add codec…</option>
                                    <?php foreach ($opts as $k => $label): ?>
                                        <option value="<?= e($k) ?>"<?= in_array((string) $k, $selected, true) ? ' disabled' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                            <template data-chip-tpl>
                                <span class="os-ms-chip" data-value="">
                                    <span class="os-ms-chip-label"></span>
                                    <button type="button" class="os-ms-chip-x" data-remove aria-label="Remove">&times;</button>
                                </span>
                            </template>
                        </div>
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
    </div>
    <div class="border-top px-3 px-md-4 py-3 d-flex flex-wrap gap-2 bg-light rounded-bottom">
        <button type="submit" class="btn btn-primary" data-once><i class="bi bi-check2 me-1"></i>Save</button>
        <a class="btn btn-outline-secondary" href="<?= e(url($base)) ?>">Cancel</a>
    </div>
</form>

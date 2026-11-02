<?php
/**
 * @var string $title @var string $base @var string $singular @var string $plural
 * @var list<array<string,mixed>> $fields @var array $result @var string $q @var array $filters @var bool $canWrite @var string $emptyHint @var array $query
 */
$cols = array_values(array_filter($fields, static fn ($f) => !empty($f['list'])));
$cell = static function (array $f, mixed $v): string {
    if ($v === null || $v === '') {
        return '<span class="text-muted">—</span>';
    }
    if (($f['type'] ?? '') === 'select' && isset($f['options'][$v])) {
        $label = (string) $f['options'][$v];
        if ($f['name'] === 'status') {
            $cls = ['active' => 'success', 'suspended' => 'warning', 'disabled' => 'secondary', 'closed' => 'dark'][$v] ?? 'secondary';
            return '<span class="badge text-bg-' . e($cls) . '">' . e($label) . '</span>';
        }
        return e($label);
    }
    if (($f['type'] ?? '') === 'number') {
        return '<span class="os-mono">' . e($v) . '</span>';
    }
    return e($v);
};
$statusField = null;
foreach ($fields as $f) {
    if ($f['name'] === 'status' && ($f['type'] ?? '') === 'select') {
        $statusField = $f;
    }
}
?>
<div class="os-page-head">
    <div>
        <h1 class="os-page-title"><?= e($plural) ?> <span class="os-count"><?= e($result['total']) ?></span></h1>
        <p class="os-page-sub">Manage <?= e(strtolower($plural)) ?> for this tenant</p>
    </div>
    <?php if ($canWrite): ?>
        <a class="btn btn-primary btn-sm" href="<?= e(url($base . '/create')) ?>"><i class="bi bi-plus-lg me-1"></i>New <?= e(strtolower($singular)) ?></a>
    <?php endif; ?>
</div>

<form method="get" class="os-toolbar row g-2 align-items-end" action="<?= e(url($base)) ?>">
    <div class="col-12 col-md-4">
        <label class="form-label small mb-1" for="q">Search</label>
        <div class="input-group input-group-sm">
            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
            <input type="search" class="form-control" id="q" name="q" value="<?= e($q) ?>" placeholder="Search…" maxlength="100" aria-label="Search">
        </div>
    </div>
    <?php if ($statusField): ?>
        <div class="col-6 col-md-3 col-lg-2">
            <label class="form-label small mb-1" for="status">Status</label>
            <select name="status" id="status" class="form-select form-select-sm" data-autosubmit aria-label="Status">
                <option value="">All statuses</option>
                <?php foreach ($statusField['options'] as $k => $label): ?>
                    <option value="<?= e($k) ?>"<?= (($filters['status'] ?? '') === (string) $k) ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <div class="col-auto"><button class="btn btn-outline-secondary btn-sm" type="submit">Filter</button></div>
</form>

<div class="os-table-wrap">
    <?php if ($result['total'] === 0): ?>
        <div class="os-empty">
            <div class="os-empty-title">No <?= e(strtolower($plural)) ?> found</div>
            <div><?= e($q !== '' || $filters ? 'Nothing matches the current filters.' : $emptyHint) ?></div>
            <?php if ($canWrite && $q === '' && !$filters): ?>
                <a class="btn btn-primary btn-sm mt-3" href="<?= e(url($base . '/create')) ?>">Create the first <?= e(strtolower($singular)) ?></a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 os-table">
                <thead><tr>
                    <th>ID</th>
                    <?php foreach ($cols as $c): ?><th><?= e($c['label']) ?></th><?php endforeach; ?>
                    <th class="text-end">Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ($result['rows'] as $row): ?>
                    <tr>
                        <td class="os-mono"><?= e($row['id']) ?></td>
                        <?php foreach ($cols as $c): ?><td><?= $cell($c, $row[$c['name']] ?? null) ?></td><?php endforeach; ?>
                        <td class="text-end os-actions">
                            <a class="btn btn-outline-secondary btn-sm" href="<?= e(url($base . '/' . (int) $row['id'] . '/edit')) ?>">
                                <i class="bi bi-<?= $canWrite ? 'pencil' : 'eye' ?> me-1"></i><?= $canWrite ? 'Edit' : 'View' ?>
                            </a>
                            <?php if ($canWrite): ?>
                                <form method="post" action="<?= e(url($base . '/' . (int) $row['id'] . '/delete')) ?>" data-confirm="Delete this <?= e(strtolower($singular)) ?>? This cannot be undone.">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php include __DIR__ . '/../partials/pagination.php'; ?>
    <?php endif; ?>
</div>

<?php
/** @var string $kind @var int $owner_id @var ?array $preview @var ?string $flash @var ?string $csv_b64 */
?>
<h1 class="h4 mb-1">Tariff CSV import / export</h1>
<p class="text-muted small">Validate before commit. Duplicate prefixes and negative rates are rejected. Customer and carrier increments are independent.</p>
<?php if (!empty($flash)): ?><div class="alert alert-info"><?= e($flash) ?></div><?php endif; ?>

<form method="get" action="<?= e(url('/tariffs/export')) ?>" class="row g-2 align-items-end mb-4">
  <div class="col-auto">
    <label class="form-label small mb-0">Kind</label>
    <select name="kind" class="form-select form-select-sm">
      <option value="customer" <?= $kind === 'customer' ? 'selected' : '' ?>>Customer (rate plan)</option>
      <option value="carrier" <?= $kind === 'carrier' ? 'selected' : '' ?>>Carrier</option>
    </select>
  </div>
  <div class="col-auto">
    <label class="form-label small mb-0">Owner ID</label>
    <input type="number" name="owner_id" class="form-control form-control-sm" value="<?= (int) $owner_id ?>" min="1" required>
  </div>
  <div class="col-auto"><button class="btn btn-outline-secondary btn-sm" type="submit">Export CSV</button></div>
</form>

<form method="post" action="<?= e(url('/tariffs/import/preview')) ?>" enctype="multipart/form-data" class="card card-body shadow-sm mb-4">
  <?= csrf_field() ?>
  <div class="row g-2">
    <div class="col-md-3">
      <label class="form-label small mb-0">Kind</label>
      <select name="kind" class="form-select form-select-sm">
        <option value="customer" <?= $kind === 'customer' ? 'selected' : '' ?>>Customer</option>
        <option value="carrier" <?= $kind === 'carrier' ? 'selected' : '' ?>>Carrier</option>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label small mb-0">Owner ID (rate_plan_id / carrier_id)</label>
      <input type="number" name="owner_id" class="form-control form-control-sm" value="<?= (int) $owner_id ?>" min="1" required>
    </div>
    <div class="col-md-4">
      <label class="form-label small mb-0">CSV file</label>
      <input type="file" name="csv" accept=".csv,text/csv" class="form-control form-control-sm" required>
    </div>
    <div class="col-md-2 d-flex align-items-end">
      <button class="btn btn-primary btn-sm w-100" type="submit">Validate</button>
    </div>
  </div>
  <p class="form-text mb-0 mt-2">Headers: <code>prefix,destination,rate,initial_increment,subsequent_increment,connect_fee,status,effective_from,effective_to</code></p>
</form>

<?php if (is_array($preview)): ?>
  <?php if (!$preview['ok']): ?>
    <ul class="text-danger"><?php foreach ($preview['errors'] as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  <?php else: ?>
    <p class="text-success">Ready to import <?= (int) count($preview['rows']) ?> row(s).</p>
    <form method="post" action="<?= e(url('/tariffs/import/commit')) ?>" class="mb-3">
      <?= csrf_field() ?>
      <input type="hidden" name="kind" value="<?= e($kind) ?>">
      <input type="hidden" name="owner_id" value="<?= (int) $owner_id ?>">
      <input type="hidden" name="csv_b64" value="<?= e($csv_b64 ?? '') ?>">
      <button class="btn btn-success btn-sm" type="submit">Commit import</button>
    </form>
  <?php endif; ?>
  <?php if (!empty($preview['rows'])): ?>
    <div class="table-responsive"><table class="table table-sm table-striped">
      <thead><tr><?php foreach (['prefix','destination','rate','initial_increment','subsequent_increment','connect_fee','status'] as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach (array_slice($preview['rows'], 0, 50) as $row): ?>
        <tr><?php foreach (['prefix','destination','rate','initial_increment','subsequent_increment','connect_fee','status'] as $h): ?><td><?= e((string) ($row[$h] ?? '')) ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
<?php endif; ?>

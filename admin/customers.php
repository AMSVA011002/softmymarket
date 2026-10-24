<?php
/**
 * Customer directory.
 */
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
admin_require('customers.view');

$filters = [
    'q' => trim((string) query('q', '')),
    'status' => (string) query('status', ''),
    'role' => (string) query('role', 'customer'),
    'sort' => (string) query('sort', 'newest'),
    'page' => query_int('page', 1),
    'per' => 25,
];
$result = Users::paginate($filters);
$rows = $result['rows'];
$returnUrl = admin_url('customers.php', array_filter($filters, static fn ($v) => $v !== '' && $v !== null));

$adminTitle = $filters['role'] === 'customer' ? 'Customers' : 'People';
$adminSubtitle = number_format($result['total']) . ' account' . ($result['total'] === 1 ? '' : 's') . ' · ' . number_format(Users::newThisMonth()) . ' joined this month';
require __DIR__ . '/includes/layout-top.php';
?>
<form class="card-soft p-3 mb-3" method="get" action="<?= e(admin_url('customers.php')) ?>">
  <div class="row g-2 align-items-end">
    <div class="col-12 col-md-5">
      <label class="form-label small mb-1" for="u-q">Search</label>
      <input class="form-control form-control-sm" id="u-q" name="q" value="<?= e($filters['q']) ?>" placeholder="Name, email or phone">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small mb-1" for="u-role">Role</label>
      <select class="form-select form-select-sm" id="u-role" name="role">
        <option value="">Everyone</option>
        <?php foreach (['customer' => 'Customers', 'staff' => 'Store staff', 'admin' => 'Admins'] as $value => $label): ?>
          <option value="<?= e($value) ?>" <?= $filters['role'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small mb-1" for="u-status">Status</label>
      <select class="form-select form-select-sm" id="u-status" name="status">
        <option value="">Any</option>
        <?php foreach (['active' => 'Active', 'suspended' => 'Suspended', 'pending' => 'Pending'] as $value => $label): ?>
          <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small mb-1" for="u-sort">Sort</label>
      <select class="form-select form-select-sm" id="u-sort" name="sort">
        <?php foreach (['newest' => 'Newest', 'oldest' => 'Oldest', 'name' => 'Name', 'spend' => 'Biggest spenders'] as $value => $label): ?>
          <option value="<?= e($value) ?>" <?= $filters['sort'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-1 d-grid">
      <button class="btn btn-sm btn-brand" type="submit">Go</button>
    </div>
  </div>
</form>

<div class="card-soft p-0">
  <div class="table-responsive">
    <table class="table table-admin mb-0">
      <thead><tr><th>Customer</th><th class="d-none d-lg-table-cell">Contact</th><th class="text-center">Orders</th><th class="text-end">Spent</th><th>Status</th><th>Joined</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td>
              <div class="d-flex align-items-center gap-2">
                <span class="logo-mark" style="width:32px;height:32px;font-size:.65rem"><?= e(initials((string) $row['name'], 2)) ?></span>
                <div class="min-w-0">
                  <a class="text-decoration-none fw-semibold d-block text-truncate" style="max-width:200px" href="<?= e(admin_url('customer.php', ['id' => (int) $row['id']])) ?>"><?= e($row['name']) ?></a>
                  <div class="text-ink-400" style="font-size:.68rem"><?= e(role_label((string) $row['role'])) ?></div>
                </div>
              </div>
            </td>
            <td class="d-none d-lg-table-cell small">
              <div class="text-truncate" style="max-width:220px"><?= e($row['email']) ?></div>
              <div class="text-ink-400" style="font-size:.68rem"><?= e(phone_display((string) ($row['phone'] ?? '')) ?: '—') ?></div>
            </td>
            <td class="text-center"><?= (int) ($row['orders_count'] ?? 0) ?></td>
            <td class="text-end small fw-semibold"><?= e(money((float) ($row['total_spent'] ?? 0))) ?></td>
            <td><span class="badge text-bg-<?= $row['status'] === 'active' ? 'success' : 'secondary' ?>"><?= e(humanize((string) $row['status'])) ?></span></td>
            <td class="small text-ink-500"><?= e(fmt_date((string) $row['created_at'])) ?></td>
            <td class="text-end">
              <a class="btn btn-sm btn-soft" href="<?= e(admin_url('customer.php', ['id' => (int) $row['id']])) ?>"><i class="bi bi-chevron-right"></i></a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($rows === []): ?><tr><td colspan="7" class="text-center small text-ink-500 py-4">No accounts match.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="mt-3"><?= pagination_links($result['pager'], array_filter($filters, static fn ($v) => $v !== '' && $v !== null)) ?></div>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>

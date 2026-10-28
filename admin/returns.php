<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
admin_require('orders.view');
$status = strtoupper((string) query('status', ''));
$q = trim((string) query('q', ''));
$result = ReturnService::adminList($status, $q, query_int('page', 1), 25);
$adminTitle = 'Returns';
$adminSubtitle = number_format($result['total']) . ' return request' . ($result['total'] === 1 ? '' : 's');
$adminActions = '<a class="btn btn-sm btn-soft" href="' . e(admin_url('returns.php')) . '"><i class="bi bi-arrow-counterclockwise me-1"></i> Reset</a>';
require __DIR__ . '/includes/layout-top.php';
?>
<form class="card-soft p-3 mb-3" method="get" action="<?= e(admin_url('returns.php')) ?>">
  <div class="row g-2 align-items-end"><div class="col-md-7"><label class="form-label small" for="return-search">Search</label><input class="form-control form-control-sm" id="return-search" name="q" value="<?= e($q) ?>" placeholder="Return number, order, customer or email"></div>
    <div class="col-md-3"><label class="form-label small" for="return-status">Status</label><select class="form-select form-select-sm" id="return-status" name="status"><option value="">All statuses</option><?php foreach (ReturnService::TRANSITIONS as $code => $_): ?><option value="<?= e($code) ?>" <?= $status === $code ? 'selected' : '' ?>><?= e(humanize($code)) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><button class="btn btn-brand btn-sm w-100" type="submit">Filter</button></div></div>
</form>
<div class="card-soft p-0"><div class="table-responsive"><table class="table table-admin align-middle mb-0"><thead><tr><th>Return</th><th>Order</th><th>Customer</th><th>Reason</th><th>Items</th><th>Requested</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($result['rows'] as $row): ?><tr><td><a class="fw-semibold text-decoration-none" href="<?= e(admin_url('return.php', ['id' => (int) $row['id']])) ?>"><?= e($row['return_number']) ?></a></td><td><a href="<?= e(admin_url('order.php', ['id' => (int) $row['order_id']])) ?>"><?= e($row['order_number']) ?></a></td><td><?= e($row['customer_name']) ?><small class="d-block text-ink-400"><?= e($row['email']) ?></small></td><td><?= e(ReturnService::REASONS[$row['reason']] ?? humanize($row['reason'])) ?></td><td><?= (int) $row['line_count'] ?></td><td class="small text-nowrap"><?= e(fmt_datetime((string) $row['created_at'])) ?></td><td><span class="badge text-bg-<?= in_array($row['status'], ['REJECTED','CANCELLED'], true) ? 'secondary' : (in_array($row['status'], ['REFUNDED','EXCHANGED','CLOSED'], true) ? 'success' : 'warning') ?>"><?= e(humanize($row['status'])) ?></span></td><td><a class="btn btn-sm btn-soft" href="<?= e(admin_url('return.php', ['id' => (int) $row['id']])) ?>">Manage</a></td></tr><?php endforeach; ?>
<?php if ($result['rows'] === []): ?><tr><td colspan="8" class="text-center py-5 small text-ink-500">No return requests found.</td></tr><?php endif; ?>
</tbody></table></div></div>
<div class="mt-3"><?= pagination_links($result['pager'], array_filter(['status' => $status, 'q' => $q], static fn ($value) => $value !== '')) ?></div>
<?php require __DIR__ . '/includes/layout-bottom.php'; ?>

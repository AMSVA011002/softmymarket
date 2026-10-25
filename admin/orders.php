<?php
/**
 * Order queue with the filters the support desk actually uses.
 */
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
admin_require('orders.view');

$filters = [
    'status' => strtoupper((string) query('status', '')),
    'payment' => (string) query('payment', ''),
    'payment_status' => strtoupper((string) query('payment_status', '')),
    'q' => trim((string) query('q', '')),
    'from' => (string) query('from', ''),
    'to' => (string) query('to', ''),
    'sort' => (string) query('sort', 'newest'),
    'page' => query_int('page', 1),
    'per' => 20,
];
$result = Orders::adminList($filters);
$rows = $result['rows'];
$itemCounts = Orders::itemCounts(array_map(static fn ($order) => (int) $order['id'], $rows));
foreach ($rows as &$orderRow) {
    $orderRow['item_count'] = $itemCounts[(int) $orderRow['id']] ?? 0;
}
unset($orderRow);
$counts = Orders::countsByStatus();
$counts['ALL'] = array_sum($counts);

$queryBase = array_filter([
    'status' => $filters['status'],
    'payment' => $filters['payment'],
    'payment_status' => $filters['payment_status'],
    'q' => $filters['q'],
    'from' => $filters['from'],
    'to' => $filters['to'],
    'sort' => $filters['sort'],
], static fn ($value) => $value !== '');

$adminTitle = 'Orders';
$adminSubtitle = number_format($result['total']) . ' order' . ($result['total'] === 1 ? '' : 's') . ' match your filters';
$adminActions = '<a class="btn btn-sm btn-soft" href="' . e(admin_url('orders.php')) . '"><i class="bi bi-arrow-counterclockwise me-1"></i> Reset</a>'
    . (Auth::can('analytics.view') ? '<a class="btn btn-sm btn-soft" href="' . e('/admin/reports.php?export=orders&' . http_build_query($queryBase)) . '"><i class="bi bi-download me-1"></i> Export</a>' : '');
require __DIR__ . '/includes/layout-top.php';
?>
<div class="d-flex gap-1 overflow-auto pb-2 mb-3" style="scrollbar-width:none">
  <?php foreach ($counts as $status => $count): ?>
    <a class="btn btn-sm <?= $filters['status'] === $status || ($status === 'ALL' && $filters['status'] === '') ? 'btn-brand' : 'btn-soft' ?> text-nowrap"
       href="<?= e(admin_url('orders.php', array_merge($queryBase, $status === 'ALL' ? ['status' => ''] : ['status' => $status]))) ?>">
      <?= $status === 'ALL' ? 'All orders' : e(order_status_label($status)) ?>
      <span class="badge rounded-pill text-bg-light ms-1"><?= (int) $count ?></span>
    </a>
  <?php endforeach; ?>
</div>

<form class="card-soft p-3 mb-3" method="get" action="<?= e(admin_url('orders.php')) ?>" data-live-filter>
  <div class="row g-2 align-items-end">
    <div class="col-12 col-md-4">
      <label class="form-label small mb-1" for="f-q">Search</label>
      <input class="form-control form-control-sm" id="f-q" name="q" type="search" value="<?= e($filters['q']) ?>"
             placeholder="Order number, name, email or phone" data-live-input autocomplete="off">
      <div class="live-search-note">Filters as you type.</div>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small mb-1" for="f-payment">Payment method</label>
      <select class="form-select form-select-sm" id="f-payment" name="payment">
        <option value="">Any</option>
        <?php foreach (PaymentManager::gateways() as $code => $gateway): ?>
          <option value="<?= e($code) ?>" <?= $filters['payment'] === $code ? 'selected' : '' ?>><?= e(payment_method_label($code)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small mb-1" for="f-pstatus">Payment status</label>
      <select class="form-select form-select-sm" id="f-pstatus" name="payment_status">
        <option value="">Any</option>
        <?php foreach (payment_statuses() as $code => $label): ?>
          <option value="<?= e($code) ?>" <?= $filters['payment_status'] === $code ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small mb-1" for="f-from">From</label>
      <input class="form-control form-control-sm" id="f-from" type="date" name="from" value="<?= e($filters['from']) ?>">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small mb-1" for="f-to">To</label>
      <input class="form-control form-control-sm" id="f-to" type="date" name="to" value="<?= e($filters['to']) ?>">
    </div>
    <?php if ($filters['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($filters['status']) ?>"><?php endif; ?>
    <div class="col-12 d-flex gap-2">
      <button class="btn btn-sm btn-brand" type="submit"><i class="bi bi-funnel me-1"></i> Apply filters</button>
      <a class="btn btn-sm btn-soft" href="<?= e(admin_url('orders.php')) ?>">Clear</a>
      <div class="ms-auto d-flex align-items-center gap-2">
        <label class="small text-ink-500 mb-0" for="f-sort">Sort</label>
        <select class="form-select form-select-sm" id="f-sort" name="sort" style="width:auto">
          <option value="newest" <?= $filters['sort'] === 'newest' ? 'selected' : '' ?>>Newest first</option>
          <option value="oldest" <?= $filters['sort'] === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
          <option value="total_desc" <?= $filters['sort'] === 'total_desc' ? 'selected' : '' ?>>Highest value</option>
          <option value="total_asc" <?= $filters['sort'] === 'total_asc' ? 'selected' : '' ?>>Lowest value</option>
        </select>
      </div>
    </div>
  </div>
</form>

<div data-live-results>
<div class="card-soft p-0">
  <div class="table-responsive">
    <table class="table table-admin align-middle mb-0">
      <thead>
        <tr>
          <th>Order</th><th>Placed</th><th>Customer</th><th class="d-none d-lg-table-cell">Items</th>
          <th>Payment</th><th class="text-end">Total</th><th>Status</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $order): ?>
          <tr>
            <td>
              <a class="fw-semibold text-decoration-none" href="<?= e(admin_url('order.php', ['id' => $order['id']])) ?>"><?= e($order['order_number']) ?></a>
              <?php if ($order['user_id'] === null): ?><span class="badge text-bg-light ms-1">Guest</span><?php endif; ?>
            </td>
            <td class="text-nowrap"><span class="d-block"><?= e(fmt_date($order['placed_at'])) ?></span><span class="text-ink-400" style="font-size:.68rem"><?= e(date('H:i', strtotime((string) $order['placed_at']))) ?></span></td>
            <td>
              <span class="d-block"><?= e(limit_text((string) $order['customer_name'], 24)) ?></span>
              <span class="text-ink-400" style="font-size:.68rem"><?= e(phone_display((string) $order['phone'])) ?></span>
            </td>
            <td class="d-none d-lg-table-cell"><?= (int) ($order['item_count'] ?? 0) ?></td>
            <td>
              <span class="d-block small"><?= e(payment_method_label((string) $order['payment_method'])) ?></span>
              <?= payment_status_badge((string) $order['payment_status']) ?>
            </td>
            <td class="text-end fw-semibold text-nowrap"><?= e(money($order['total'])) ?></td>
            <td><?= order_status_badge((string) $order['status']) ?></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-soft" href="<?= e('/admin/order.php?id=' . (int) $order['id']) ?>" title="Open"><i class="bi bi-pencil-square"></i></a>
              <a class="btn btn-sm btn-soft" href="<?= e('/invoice.php?order=' . (int) $order['id']) ?>" target="_blank" rel="noopener" title="Invoice"><i class="bi bi-receipt"></i></a>
              <?php if ($order['tracking_number']): ?>
                <span class="badge text-bg-light" title="Tracking number"><?= e($order['tracking_number']) ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($rows === []): ?>
          <tr><td colspan="8" class="text-center py-5 small text-ink-500"><i class="bi bi-inbox fs-4 d-block mb-2"></i>No orders match these filters.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="mt-3"><?= pagination_links($result['pager'], $queryBase) ?></div>
</div><!-- /data-live-results -->

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>

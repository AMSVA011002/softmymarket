<?php
/**
 * Live order board — auto-refreshes every 20s via /api/live-orders.php.
 */
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
admin_require('liveboard.view');

$counts = Orders::countsByStatus();
$open = 0;
foreach (['PENDING', 'CONFIRMED', 'PROCESSING', 'PACKED', 'SHIPPED', 'OUT_FOR_DELIVERY'] as $status) {
    $open += (int) ($counts[$status] ?? 0);
}
$orders = Orders::liveBoard(50);

$adminTitle = 'Live orders';
$adminSubtitle = 'Updates automatically every 20 seconds while this tab is open';
$adminActions = '<a class="btn btn-sm btn-soft" href="' . e(admin_url('orders.php')) . '"><i class="bi bi-list-ul me-1"></i> Full order list</a>';
require __DIR__ . '/includes/layout-top.php';
?>
<div class="row g-2 g-md-3 mb-3">
  <?php
  $tiles = [
      ['In the pipeline', $open, 'bi-broadcast-pin', 'brand'],
      ['Awaiting confirmation', (int) ($counts['PENDING'] ?? 0), 'bi-hourglass-split', 'amber'],
      ['Packed', (int) ($counts['PACKED'] ?? 0), 'bi-box-seam', 'ocean'],
      ['Out for delivery', (int) ($counts['OUT_FOR_DELIVERY'] ?? 0), 'bi-truck', 'brand'],
  ];
  foreach ($tiles as [$label, $value, $icon, $tone]): ?>
    <div class="col-6 col-lg-3">
      <div class="kpi-card">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <div class="kpi-label"><?= e($label) ?></div>
            <div class="kpi-value" data-live-count><?= (int) $value ?></div>
          </div>
          <span class="kpi-icon" style="<?= $tone === 'amber' ? 'background:var(--amber-soft);color:var(--amber-700)' : ($tone === 'ocean' ? 'background:var(--ocean-50);color:var(--ocean-700)' : '') ?>"><i class="bi <?= e($icon) ?>"></i></span>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card-soft p-0" data-live-board="20">
  <div class="d-flex align-items-center justify-content-between px-3 pt-3 pb-2">
    <h2 class="h6 fw-bold mb-0"><span class="status-pill live"><span class="dot"></span> Live</span> Orders needing attention</h2>
    <span class="small text-ink-400">Newest first · refreshes automatically</span>
  </div>
  <div class="table-responsive">
    <table class="table table-admin mb-0">
      <thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Payment</th><th class="text-end">Total</th><th></th></tr></thead>
      <tbody data-live-list>
        <?php foreach ($orders as $order): ?>
          <tr>
            <td>
              <a class="fw-semibold text-decoration-none" href="<?= e(admin_url('order.php', ['id' => $order['id']])) ?>"><?= e($order['order_number']) ?></a>
              <div class="text-ink-400" style="font-size:.7rem"><?= e(time_ago((string) $order['placed_at'])) ?></div>
            </td>
            <td class="small">
              <div><?= e($order['customer_name']) ?></div>
              <div class="text-ink-400" style="font-size:.7rem"><?= e(phone_display((string) $order['phone'])) ?></div>
            </td>
            <td><?= order_status_badge((string) $order['status']) ?></td>
            <td class="small"><?= e(payment_method_label((string) $order['payment_method'])) ?></td>
            <td class="text-end small fw-semibold"><?= e(money($order['total'])) ?></td>
            <td class="text-end"><a class="btn btn-sm btn-soft" href="<?= e(admin_url('order.php', ['id' => $order['id']])) ?>"><i class="bi bi-chevron-right"></i></a></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($orders === []): ?>
          <tr><td colspan="6" class="text-center small text-ink-500 py-4"><i class="bi bi-check2-circle fs-4 d-block mb-1"></i>Nothing waiting — every order is delivered or closed.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>

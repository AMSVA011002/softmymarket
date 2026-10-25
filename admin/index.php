<?php
/**
 * Dashboard home — the numbers a shop owner opens the day with.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
admin_require('dashboard.view');

$stats = Orders::stats();
$today = Orders::countsByStatus();
$series = Orders::salesSeries(14);
$recent = Orders::recent(8);
$lowStock = Catalog::lowStock(8);
$pendingTransfers = Orders::pendingBankTransfers(5);
$pendingReviews = Catalog::adminReviews(['status' => 'pending', 'per' => 5]);
$topProducts = Orders::topProducts(5);
$live = Orders::liveBoard(6);
$inv = Reports::inventory();
$newCustomers = Users::newThisMonth();
$catalogCount = Catalog::productCount();

$maxSales = 0.0;
foreach ($series as $point) {
    $maxSales = max($maxSales, (float) $point['sales']);
}

$adminTitle = 'Dashboard';
$adminSubtitle = 'Today, ' . date('l j F Y') . ' · ' . Settings::get('store_name', 'SoftMyMarket');
$adminActions = '<a class="btn btn-sm btn-brand" href="' . e(admin_url('product-form.php')) . '"><i class="bi bi-plus-lg me-1"></i> Add product</a>'
    . '<a class="btn btn-sm btn-soft" href="' . e(admin_url('reports.php')) . '"><i class="bi bi-graph-up-arrow me-1"></i> Reports</a>';
require __DIR__ . '/includes/layout-top.php';
?>
<div class="row g-2 g-md-3">
  <?php
  $kpis = [
      ['Today\'s sales', money($stats['today_sales']), $stats['today_count'] . ' order' . ($stats['today_count'] === 1 ? '' : 's'), 'bi-cash-stack', 'brand'],
      ['This month', money($stats['month_sales']), $stats['month_count'] . ' orders', 'bi-calendar-check', 'ocean'],
      ['Average order', money($stats['average_value']), 'Lifetime: ' . money($stats['total_sales']), 'bi-receipt', 'brand'],
      ['Unpaid orders', (string) $stats['pending_payments'], 'Awaiting payment', 'bi-exclamation-circle', 'amber'],
  ];
  foreach ($kpis as [$label, $value, $hint, $icon, $tone]): ?>
    <div class="col-6 col-xl-3">
      <div class="kpi-card h-100">
        <div class="d-flex justify-content-between align-items-start gap-2">
          <div class="min-w-0">
            <div class="kpi-label"><?= e($label) ?></div>
            <div class="kpi-value text-truncate"><?= e($value) ?></div>
            <div class="kpi-delta text-ink-500 mt-1"><?= e($hint) ?></div>
          </div>
          <span class="kpi-icon" style="<?= $tone === 'ocean' ? 'background:var(--ocean-50);color:var(--ocean-700)' : ($tone === 'amber' ? 'background:var(--amber-soft);color:var(--amber-700)' : '') ?>">
            <i class="bi <?= e($icon) ?>"></i>
          </span>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="row g-3 mt-1">
  <div class="col-lg-8">
    <div class="card-soft p-3 h-100">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
          <h2 class="h6 fw-bold mb-0">Sales · last 14 days</h2>
          <p class="small text-ink-500 mb-0"><?= e(money(array_sum(array_column($series, 'sales')))) ?> collected across <?= (int) array_sum(array_column($series, 'orders')) ?> orders</p>
        </div>
        <a class="small text-decoration-none" href="<?= e(admin_url('reports.php', ['range' => '30'])) ?>">Full report <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="chart-bars" role="img" aria-label="Daily sales for the last 14 days">
        <?php foreach ($series as $point):
            $height = $maxSales > 0 ? max(2, (int) round(((float) $point['sales'] / $maxSales) * 100)) : 2; ?>
          <div style="height:<?= $height ?>%" title="<?= e(fmt_date($point['date'])) ?>: <?= e(money($point['sales'])) ?> (<?= (int) $point['orders'] ?> orders)"></div>
        <?php endforeach; ?>
      </div>
      <div class="d-flex justify-content-between small text-ink-400 mt-2">
        <span><?= e(fmt_date($series[0]['date'] ?? date('Y-m-d'), 'j M')) ?></span>
        <span><?= e(fmt_date(end($series)['date'] ?? date('Y-m-d'), 'j M')) ?></span>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card-soft p-3 h-100">
      <h2 class="h6 fw-bold mb-2">Needs attention</h2>
      <div class="d-grid gap-2">
        <?php
        $attention = [
            ['Bank transfers to verify', $pendingTransfers === [] ? 0 : count($pendingTransfers), admin_url('payments.php'), 'bi-bank'],
            ['Reviews awaiting approval', (int) ($pendingReviews['total'] ?? 0), admin_url('reviews.php', ['status' => 'pending']), 'bi-star'],
            ['Products low or out of stock', (int) $inv['low_stock'] + (int) $inv['out_of_stock'], admin_url('inventory.php', ['filter' => 'low']), 'bi-boxes'],
            ['New customers this month', $newCustomers, admin_url('customers.php'), 'bi-people'],
          ];
          foreach ($attention as [$label, $count, $href, $icon]): ?>
          <a class="d-flex align-items-center gap-2 p-2 rounded-3 text-decoration-none text-ink-800" style="border:1px solid var(--ink-200)" href="<?= e($href) ?>">
            <span class="kpi-icon" style="width:32px;height:32px;border-radius:9px;font-size:.9rem"><i class="bi <?= e($icon) ?>"></i></span>
            <span class="small flex-grow-1"><?= e($label) ?></span>
            <span class="badge text-bg-<?= $count > 0 ? 'warning' : 'light' ?> rounded-pill"><?= (int) $count ?></span>
          </a>
        <?php endforeach; ?>
        <div class="d-flex align-items-center gap-2 p-2 rounded-3" style="border:1px solid var(--ink-200)">
          <span class="kpi-icon" style="width:32px;height:32px;border-radius:9px;font-size:.9rem"><i class="bi bi-bag"></i></span>
          <span class="small flex-grow-1">Catalogue size</span>
          <span class="badge text-bg-light rounded-pill"><?= number_format($catalogCount) ?></span>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mt-1">
  <div class="col-lg-7">
    <div class="card-soft p-0 h-100">
      <div class="d-flex justify-content-between align-items-center px-3 pt-3 pb-2">
        <h2 class="h6 fw-bold mb-0">Latest orders</h2>
        <a class="small text-decoration-none" href="<?= e(admin_url('orders.php')) ?>">All orders <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="table-responsive">
        <table class="table table-admin align-middle mb-0">
          <thead><tr><th>Order</th><th>Customer</th><th>Total</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($recent as $order): ?>
              <tr>
                <td><a class="fw-semibold text-decoration-none" href="<?= e(admin_url('order.php', ['id' => $order['id']])) ?>"><?= e($order['order_number']) ?></a><div class="text-ink-400" style="font-size:.68rem"><?= e(time_ago($order['placed_at'])) ?></div></td>
                <td><?= e(limit_text($order['customer_name'], 20)) ?><div class="text-ink-400" style="font-size:.68rem"><?= e(phone_display($order['phone'])) ?></div></td>
                <td class="fw-semibold"><?= e(money($order['total'])) ?></td>
                <td><?= order_status_badge((string) $order['status']) ?></td>
                <td class="text-end"><a class="btn btn-sm btn-soft" href="<?= e(admin_url('order.php', ['id' => $order['id']])) ?>"><i class="bi bi-chevron-right"></i></a></td>
              </tr>
            <?php endforeach; ?>
            <?php if ($recent === []): ?><tr><td colspan="5" class="text-center small text-ink-500 py-4">No orders yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card-soft p-3 h-100">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 fw-bold mb-0">Live board</h2>
        <a class="small text-decoration-none" href="<?= e(admin_url('live.php')) ?>">Open <i class="bi bi-arrow-right"></i></a>
      </div>
      <?php if ($live === []): ?>
        <p class="small text-ink-500 mb-0">Nothing in the pipeline right now.</p>
      <?php else: ?>
        <div class="d-grid gap-2">
          <?php foreach ($live as $order): ?>
            <a class="text-decoration-none text-ink-800 d-flex align-items-center gap-2 p-2 rounded-3" style="border:1px solid var(--ink-200)" href="<?= e(admin_url('order.php', ['id' => $order['id']])) ?>">
              <span class="flex-grow-1 min-w-0">
                <span class="d-block small fw-semibold text-truncate"><?= e($order['order_number']) ?> · <?= e(limit_text($order['customer_name'], 16)) ?></span>
                <span class="d-block text-ink-400" style="font-size:.68rem"><?= e(order_status_label((string) $order['status'])) ?> · <?= e(time_ago($order['updated_at'] ?? $order['placed_at'])) ?></span>
              </span>
              <?= order_status_badge((string) $order['status']) ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-3 mt-1">
  <div class="col-lg-4">
    <div class="card-soft p-3 h-100">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 fw-bold mb-0">Low stock</h2>
        <a class="small text-decoration-none" href="<?= e(admin_url('inventory.php')) ?>">Inventory</a>
      </div>
      <?php if ($lowStock === []): ?>
        <p class="small text-ink-500 mb-0"><i class="bi bi-check-circle text-brand"></i> Every product is comfortably in stock.</p>
      <?php else: ?>
        <ul class="list-unstyled small mb-0">
          <?php foreach ($lowStock as $product): ?>
            <li class="d-flex align-items-center gap-2 py-1 border-bottom">
              <img src="<?= e(img_url($product['image'], (string) $product['title'], 80)) ?>" alt="" width="32" height="32" style="border-radius:.4rem;object-fit:cover">
              <span class="flex-grow-1 text-truncate"><?= e(limit_text($product['title'], 30)) ?></span>
              <span class="badge text-bg-<?= $product['stock'] <= 0 ? 'danger' : 'warning' ?> rounded-pill"><?= (int) $product['stock'] ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card-soft p-3 h-100">
      <h2 class="h6 fw-bold mb-2">Top sellers</h2>
      <?php if ($topProducts === []): ?>
        <p class="small text-ink-500 mb-0">No sales recorded yet.</p>
      <?php else: ?>
        <ol class="list-unstyled small mb-0">
          <?php foreach ($topProducts as $index => $product): ?>
            <li class="d-flex gap-2 py-1 border-bottom">
              <span class="text-ink-400"><?= $index + 1 ?></span>
              <span class="flex-grow-1 text-truncate"><?= e(limit_text((string) $product['title'], 28)) ?></span>
              <span class="fw-semibold"><?= (int) $product['units'] ?></span>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card-soft p-3 h-100">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 fw-bold mb-0">Order pipeline</h2>
        <a class="small text-decoration-none" href="<?= e(admin_url('orders.php')) ?>">Manage</a>
      </div>
      <div class="d-grid gap-1 small">
        <?php foreach ($today as $status => $count): if ((int) $count === 0) { continue; } ?>
          <a class="d-flex justify-content-between text-decoration-none text-ink-700 py-1" href="<?= e(admin_url('orders.php', ['status' => $status])) ?>">
            <span><?= order_status_badge((string) $status) ?></span>
            <span class="fw-semibold"><?= (int) $count ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <?php if (array_sum($today) === 0): ?><p class="small text-ink-500 mb-0">No orders in the pipeline.</p><?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>

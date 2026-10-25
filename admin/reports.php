<?php
/**
 * Sales analytics with a date range, CSV export and channel breakdowns.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
admin_require('analytics.view');

$range = (string) query('range', '30');
$to = (string) query('to', date('Y-m-d'));
$from = (string) query('from', '');
if ($from === '') {
    $from = match ($range) {
        '7' => date('Y-m-d', strtotime('-6 days')),
        '90' => date('Y-m-d', strtotime('-89 days')),
        '365' => date('Y-m-d', strtotime('-364 days')),
        default => date('Y-m-d', strtotime('-29 days')),
    };
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

if (query('export') === 'sales') {
    Audit::log('reports.export', 'report', null, ['from' => $from, 'to' => $to]);
    Reports::salesCsv($from, $to);
}

$summary = Reports::summary($from, $to);
$daily = Reports::daily($from, $to);
$byCategory = Reports::byCategory($from, $to, 8);
$byPayment = Reports::byPaymentMethod($from, $to);
$byStatus = Reports::byStatus($from, $to);
$topProducts = Reports::topProducts($from, $to, 8);
$topCustomers = Reports::topCustomers($from, $to, 8);
$coupons = Reports::couponPerformance($from, $to, 8);
$inventory = Reports::inventory();
$maxRevenue = 0.0;
foreach ($daily as $point) {
    $maxRevenue = max($maxRevenue, (float) $point['revenue']);
}
$exportUrl = admin_url('reports.php', ['export' => 'sales', 'from' => $from, 'to' => $to]);

$adminTitle = 'Reports';
$adminSubtitle = fmt_date($from) . ' → ' . fmt_date($to) . ' · ' . number_format($summary['orders']) . ' orders';
$adminActions = '<a class="btn btn-sm btn-soft" href="' . e($exportUrl) . '"><i class="bi bi-download me-1"></i> Export CSV</a>';
require __DIR__ . '/includes/layout-top.php';
?>
<form class="card-soft p-3 mb-3" method="get" action="<?= e(admin_url('reports.php')) ?>">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-3">
      <label class="form-label small mb-1" for="r-from">From</label>
      <input class="form-control form-control-sm" id="r-from" type="date" name="from" value="<?= e($from) ?>">
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small mb-1" for="r-to">To</label>
      <input class="form-control form-control-sm" id="r-to" type="date" name="to" value="<?= e($to) ?>">
    </div>
    <div class="col-12 col-md-4">
      <label class="form-label small mb-1">Quick ranges</label>
      <div class="d-flex gap-1 flex-wrap">
        <?php foreach (['7' => '7 days', '30' => '30 days', '90' => '90 days', '365' => '12 months'] as $value => $label): ?>
          <a class="btn btn-sm <?= $range === $value ? 'btn-brand' : 'btn-soft' ?>" href="<?= e(admin_url('reports.php', ['range' => $value])) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="col-12 col-md-2 d-grid"><button class="btn btn-sm btn-brand" type="submit">Apply</button></div>
  </div>
</form>

<div class="row g-2 g-md-3 mb-3">
  <?php
  $tiles = [
      ['Revenue', money($summary['revenue']), $summary['growth'] !== null ? sprintf('%+.1f%% vs previous period', $summary['growth']) : 'No comparison yet', 'bi-cash-stack'],
      ['Orders', number_format($summary['orders']), number_format($summary['customers']) . ' unique customers', 'bi-box-seam'],
      ['Average order', money($summary['aov']), 'Gross ' . money($summary['gross']), 'bi-receipt'],
      ['Collected', money($summary['collected']), 'Outstanding ' . money($summary['outstanding']), 'bi-bank'],
  ];
  foreach ($tiles as [$label, $value, $hint, $icon]): ?>
    <div class="col-6 col-xl-3">
      <div class="kpi-card h-100">
        <div class="d-flex justify-content-between align-items-start gap-2">
          <div class="min-w-0">
            <div class="kpi-label"><?= e($label) ?></div>
            <div class="kpi-value text-truncate"><?= e($value) ?></div>
            <div class="kpi-delta text-ink-500 mt-1"><?= e($hint) ?></div>
          </div>
          <span class="kpi-icon"><i class="bi <?= e($icon) ?>"></i></span>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card-soft p-3 mb-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h6 fw-bold mb-0">Daily revenue</h2>
    <span class="small text-ink-400">Peak <?= e(money($maxRevenue)) ?></span>
  </div>
  <div class="chart-bars" style="height:160px">
    <?php foreach ($daily as $point): ?>
      <div style="height:<?= $maxRevenue > 0 ? max(2, (int) round(((float) $point['revenue'] / $maxRevenue) * 100)) : 2 ?>%"
           title="<?= e($point['label']) ?>: <?= e(money((float) $point['revenue'])) ?> · <?= (int) $point['orders'] ?> orders"></div>
    <?php endforeach; ?>
  </div>
  <div class="d-flex justify-content-between small text-ink-400 mt-2">
    <span><?= e($daily[0]['label'] ?? '') ?></span>
    <span><?= e(end($daily)['label'] ?? '') ?></span>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card-soft p-3 h-100">
      <h2 class="h6 fw-bold mb-2">Revenue by category</h2>
      <table class="table table-admin mb-0">
        <thead><tr><th>Category</th><th class="text-center">Units</th><th class="text-end">Revenue</th></tr></thead>
        <tbody>
          <?php foreach ($byCategory as $row): ?>
            <tr><td><?= e((string) ($row['name'] ?? 'Uncategorised')) ?></td><td class="text-center"><?= (int) $row['units'] ?></td><td class="text-end"><?= e(money((float) $row['revenue'])) ?></td></tr>
          <?php endforeach; ?>
          <?php if ($byCategory === []): ?><tr><td colspan="3" class="small text-ink-500 py-3">No sales in this period.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card-soft p-3 h-100">
      <h2 class="h6 fw-bold mb-2">Payment methods</h2>
      <table class="table table-admin mb-0">
        <thead><tr><th>Method</th><th class="text-center">Paid</th><th class="text-end">Collected</th></tr></thead>
        <tbody>
          <?php foreach ($byPayment as $row): ?>
            <tr>
              <td><?= e(payment_method_label(PaymentManager::methodCode((string) $row['gateway']))) ?></td>
              <td class="text-center"><?= (int) $row['c'] ?></td>
              <td class="text-end"><?= e(money((float) $row['t'])) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($byPayment === []): ?><tr><td colspan="3" class="small text-ink-500 py-3">No payments captured yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
      <hr>
      <h3 class="h6 fw-bold mb-2">Order status mix</h3>
      <div class="d-flex flex-wrap gap-2">
        <?php foreach ($byStatus as $row): ?>
          <span class="status-pill"><?= e(order_status_label((string) $row['status'])) ?> · <?= (int) $row['c'] ?></span>
        <?php endforeach; ?>
        <?php if ($byStatus === []): ?><span class="small text-ink-500">No orders.</span><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card-soft p-3 h-100">
      <h2 class="h6 fw-bold mb-2">Top products</h2>
      <table class="table table-admin mb-0">
        <thead><tr><th>Product</th><th class="text-center">Units</th><th class="text-end">Revenue</th></tr></thead>
        <tbody>
          <?php foreach ($topProducts as $row): ?>
            <tr>
              <td class="text-truncate" style="max-width:260px"><?= e((string) ($row['title'] ?? 'Deleted product')) ?></td>
              <td class="text-center"><?= (int) $row['units'] ?></td>
              <td class="text-end"><?= e(money((float) $row['revenue'])) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($topProducts === []): ?><tr><td colspan="3" class="small text-ink-500 py-3">No product sales yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card-soft p-3 h-100">
      <h2 class="h6 fw-bold mb-2">Top customers</h2>
      <table class="table table-admin mb-0">
        <thead><tr><th>Customer</th><th class="text-center">Orders</th><th class="text-end">Spent</th></tr></thead>
        <tbody>
          <?php foreach ($topCustomers as $row): ?>
            <tr>
              <td class="text-truncate" style="max-width:220px"><?= e((string) $row['name']) ?><div class="text-ink-400" style="font-size:.68rem"><?= e((string) $row['email']) ?></div></td>
              <td class="text-center"><?= (int) $row['orders'] ?></td>
              <td class="text-end"><?= e(money((float) $row['spend'])) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($topCustomers === []): ?><tr><td colspan="3" class="small text-ink-500 py-3">No customer activity yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card-soft p-3 h-100">
      <h2 class="h6 fw-bold mb-2">Coupon performance</h2>
      <table class="table table-admin mb-0">
        <thead><tr><th>Code</th><th class="text-center">Uses</th><th class="text-end">Discount given</th></tr></thead>
        <tbody>
          <?php foreach ($coupons as $row): ?>
            <tr>
              <td class="font-monospace small"><?= e((string) $row['code']) ?></td>
              <td class="text-center"><?= (int) $row['uses'] ?></td>
              <td class="text-end"><?= e(money((float) $row['given'])) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($coupons === []): ?><tr><td colspan="3" class="small text-ink-500 py-3">No coupon redemptions yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card-soft p-3 h-100">
      <h2 class="h6 fw-bold mb-2">Inventory health</h2>
      <dl class="row small mb-0">
        <dt class="col-6 text-ink-500 fw-normal">SKUs tracked</dt><dd class="col-6 text-end"><?= number_format($inventory['skus']) ?></dd>
        <dt class="col-6 text-ink-500 fw-normal">Units on hand</dt><dd class="col-6 text-end"><?= number_format($inventory['units']) ?></dd>
        <dt class="col-6 text-ink-500 fw-normal">Stock at cost</dt><dd class="col-6 text-end"><?= e(money($inventory['cost_value'])) ?></dd>
        <dt class="col-6 text-ink-500 fw-normal">Retail value</dt><dd class="col-6 text-end"><?= e(money($inventory['retail_value'])) ?></dd>
        <dt class="col-6 text-ink-500 fw-normal">Low stock</dt><dd class="col-6 text-end text-warning fw-semibold"><?= number_format($inventory['low_stock']) ?></dd>
        <dt class="col-6 text-ink-500 fw-normal">Out of stock</dt><dd class="col-6 text-end text-danger fw-semibold"><?= number_format($inventory['out_of_stock']) ?></dd>
      </dl>
      <a class="btn btn-sm btn-soft mt-2 w-100" href="<?= e(admin_url('inventory.php', ['filter' => 'low'])) ?>">Review stock</a>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>

<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
Auth::requireLogin();

$orderId = query_int('order');
$order = $orderId > 0 ? Orders::find($orderId) : null;
if ($order === null) {
    abort(404, 'Order not found.');
}
if (!Auth::isTeam() && !Orders::ownedBy($order, Auth::id())) {
    abort(403, 'You do not have access to this invoice.');
}

$items = Orders::items((int) $order['id']);
$brand = Settings::brand();
$address = $order['shipping_address_array'];
$paid = 0.0;
foreach (Orders::payments((int) $order['id']) as $payment) {
    if (strtolower((string) $payment['status']) === 'paid') {
        $paid += (float) $payment['amount'];
    }
}
$pageTitle = 'Invoice ' . $order['order_number'];
$noindex = true;
require INCLUDES_PATH . '/layout/header.php';
?>
<section class="section">
  <div class="container-page" style="max-width:900px">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <a class="btn btn-soft btn-sm" href="<?= Auth::isTeam() ? '/admin/order.php?id=' . (int) $order['id'] : '/account/order.php?id=' . (int) $order['id'] ?>"><i class="bi bi-arrow-left"></i> Back</a>
      <button class="btn btn-brand btn-sm" type="button" data-print><i class="bi bi-printer"></i> Print / save PDF</button>
    </div>

    <div class="card-soft p-4 invoice-sheet">
      <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div>
          <div class="d-flex align-items-center gap-2 mb-2">
            <span class="logo-mark"><?= e($brand['initials']) ?></span>
            <div>
              <div class="fw-bold"><?= e($brand['name']) ?></div>
              <div class="text-ink-500" style="font-size:.72rem"><?= e($brand['address']) ?></div>
            </div>
          </div>
          <div class="small text-ink-600"><?= e($brand['phone']) ?> · <?= e($brand['email']) ?></div>
        </div>
        <div class="text-end">
          <h1 class="h5 fw-bold mb-1">Invoice</h1>
          <div class="small"><?= e($order['order_number']) ?></div>
          <div class="small text-ink-500">Placed <?= e(fmt_date((string) $order['placed_at'])) ?></div>
          <div class="mt-2"><?= order_status_badge((string) $order['status']) ?> <?= payment_status_badge((string) $order['payment_status']) ?></div>
        </div>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-sm-6">
          <div class="text-ink-500 small text-uppercase fw-bold" style="letter-spacing:.1em;font-size:.66rem">Billed to</div>
          <address class="small mb-0">
            <strong><?= e($order['customer_name']) ?></strong><br>
            <?= e($order['email']) ?><br>
            <?= e(phone_display((string) $order['phone'])) ?><br>
            <?= e($address['line1'] ?? '') ?><?= !empty($address['line2']) ? ', ' . e($address['line2']) : '' ?><br>
            <?= e(trim(($address['city'] ?? '') . ' ' . ($address['postal_code'] ?? ''))) ?><?= !empty($address['district']) ? ', ' . e($address['district']) : '' ?>
          </address>
        </div>
        <div class="col-sm-6 text-sm-end">
          <div class="text-ink-500 small text-uppercase fw-bold" style="letter-spacing:.1em;font-size:.66rem">Payment</div>
          <div class="small"><?= e(payment_method_label((string) $order['payment_method'])) ?></div>
          <div class="small text-ink-500">Currency: <?= e((string) $order['currency']) ?></div>
          <?php if (!empty($order['tracking_number'])): ?>
            <div class="small text-ink-500 mt-1">Tracking: <?= e($order['tracking_number']) ?><?= !empty($order['carrier']) ? ' (' . e($order['carrier']) . ')' : '' ?></div>
          <?php endif; ?>
        </div>
      </div>

      <?php require INCLUDES_PATH . '/partials/order-items-table.php'; ?>

      <div class="row g-2 mt-4">
        <div class="col-sm-7 small text-ink-500">
          <p class="mb-1">Payment received: <strong><?= e(money($paid)) ?></strong></p>
          <p class="mb-0">Thank you for shopping with <?= e($brand['name']) ?>.</p>
        </div>
        <div class="col-sm-5 text-sm-end small">
          <div>Questions? <?= e($brand['email']) ?></div>
          <div><?= e($brand['phone']) ?></div>
        </div>
      </div>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

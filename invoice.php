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

$isAdmin = Auth::isTeam();
$printMode = $isAdmin ? (string) query('print', '') : '';
if (!in_array($printMode, ['', 'a5', 'label'], true)) {
    $printMode = '';
}
$isA5Print = $printMode === 'a5';
$isShippingLabel = $printMode === 'label';
$pageClass = 'invoice-page' . ($printMode !== '' ? ' invoice-print-page invoice-print-' . $printMode : '');
$items = $isShippingLabel ? [] : Orders::items((int) $order['id']);
$showWarranty = !$isShippingLabel;
$brand = Settings::brand();
$address = $order['shipping_address_array'];
$paid = 0.0;
foreach (Orders::payments((int) $order['id']) as $payment) {
    if (strtolower((string) $payment['status']) === 'paid') {
        $paid += (float) $payment['amount'];
    }
}
$isCod = strtoupper((string) $order['payment_method']) === 'COD';
$adminPaid = !$isCod && (
    strtoupper((string) $order['payment_status']) === 'PAID'
    || ($order['total'] > 0 && $paid >= (float) $order['total'] - 0.01)
);
$pageTitle = ($isShippingLabel ? 'Package label ' : 'Invoice ') . $order['order_number'];
$noindex = true;
require INCLUDES_PATH . '/layout/header.php';
?>
<section class="section">
  <div class="container-page" style="max-width:900px">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 invoice-controls">
      <a class="btn btn-soft btn-sm" href="<?= $isAdmin ? '/admin/order.php?id=' . (int) $order['id'] : '/account/order.php?id=' . (int) $order['id'] ?>"><i class="bi bi-arrow-left"></i> Back</a>
      <div class="d-flex flex-wrap justify-content-end gap-2">
        <?php if ($isAdmin && !$isA5Print && !$isShippingLabel): ?>
          <a class="btn btn-soft btn-sm" href="/invoice.php?order=<?= (int) $order['id'] ?>&amp;print=a5"><i class="bi bi-file-earmark-text"></i> A5 admin invoice</a>
          <a class="btn btn-soft btn-sm" href="/invoice.php?order=<?= (int) $order['id'] ?>&amp;print=label"><i class="bi bi-box-seam"></i> Package sticker</a>
        <?php endif; ?>
        <button class="btn btn-brand btn-sm" type="button" data-print><i class="bi bi-printer"></i> <?= $isA5Print ? 'Print A5 invoice' : ($isShippingLabel ? 'Print package sticker' : 'Print / save PDF') ?></button>
      </div>
    </div>

    <?php if ($isShippingLabel): ?>
      <article class="shipping-label" aria-label="Package shipping label">
        <div class="shipping-label-brand">
          <?php if (!empty($brand['logo'])): ?>
            <img class="shipping-label-logo" src="<?= e(img_url((string) $brand['logo'], (string) $brand['name'], 320)) ?>" alt="<?= e((string) $brand['name']) ?>">
          <?php else: ?>
            <span class="logo-mark" aria-hidden="true"><?= e($brand['initials']) ?></span>
          <?php endif; ?>
          <div class="fw-bold mt-2"><?= e($brand['name']) ?></div>
          <div class="small text-ink-500"><?= e($brand['address']) ?></div>
          <div class="small"><?= e($brand['phone']) ?> · <?= e($brand['email']) ?></div>
        </div>
        <div class="shipping-label-order">
          <span class="small text-uppercase text-ink-500 fw-bold">Order reference</span>
          <strong><?= e($order['order_number']) ?></strong>
          <span class="small text-ink-500">Placed <?= e(fmt_date((string) $order['placed_at'])) ?></span>
        </div>
        <div class="shipping-label-recipient">
          <span class="small text-uppercase text-ink-500 fw-bold">Deliver to</span>
          <strong><?= e($order['customer_name']) ?></strong>
          <span><?= e($order['email']) ?></span>
          <span><?= e(phone_display((string) $order['phone'])) ?></span>
          <span><?= e($address['line1'] ?? '') ?><?= !empty($address['line2']) ? ', ' . e($address['line2']) : '' ?></span>
          <span><?= e(trim(($address['city'] ?? '') . ' ' . ($address['postal_code'] ?? ''))) ?><?= !empty($address['district']) ? ', ' . e($address['district']) : '' ?></span>
          <?php if (!empty($address['country'])): ?><span><?= e($address['country']) ?></span><?php endif; ?>
        </div>
        <div class="shipping-label-payment">
          <span><?= e(payment_method_label((string) $order['payment_method'])) ?></span>
          <?php if ($isCod): ?>
            <strong>COD AMOUNT DUE: <?= e(money($order['total'])) ?></strong>
          <?php else: ?>
            <strong><?= $adminPaid ? 'PREPAID' : 'PAYMENT NOT CONFIRMED' ?></strong>
          <?php endif; ?>
        </div>
      </article>
    <?php else: ?>
    <div class="card-soft p-4 invoice-sheet <?= $isA5Print ? 'invoice-sheet-a5' : '' ?>">
      <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
        <div class="invoice-brand-block">
          <?php if (!empty($brand['logo'])): ?>
            <img class="invoice-brand-logo" src="<?= e(img_url((string) $brand['logo'], (string) $brand['name'], 320)) ?>" alt="<?= e((string) $brand['name']) ?>">
          <?php else: ?>
            <span class="logo-mark invoice-brand-mark" aria-hidden="true"><?= e($brand['initials']) ?></span>
          <?php endif; ?>
          <div class="invoice-brand-name fw-bold"><?= e($brand['name']) ?></div>
          <div class="invoice-brand-details text-ink-500"><?= e($brand['address']) ?></div>
          <div class="invoice-brand-details small text-ink-600"><?= e($brand['phone']) ?> · <?= e($brand['email']) ?></div>
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
          <?php if ($isA5Print): ?>
            <p class="mb-1">Payment received: <strong class="<?= $adminPaid ? 'text-brand' : 'text-danger' ?>"><?= $adminPaid ? 'Paid' : 'Not Paid' ?></strong></p>
            <?php if ($paid > 0): ?><p class="mb-1">Amount received: <strong><?= e(money($paid)) ?></strong></p><?php endif; ?>
          <?php endif; ?>
          <p class="mb-0">Thank you for shopping with <?= e($brand['name']) ?>.</p>
        </div>
        <div class="col-sm-5 text-sm-end small">
          <div>Questions? <?= e($brand['email']) ?></div>
          <div><?= e($brand['phone']) ?></div>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

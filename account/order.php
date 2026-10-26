<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
Auth::requireLogin();

$orderId = query_int('id');
$order = $orderId > 0 ? Orders::find($orderId) : null;
if ($order === null || !Orders::ownedBy($order, Auth::id())) {
    abort(404, 'Order not found.');
}

$items = Orders::items((int) $order['id']);
$events = Orders::events((int) $order['id'], true);
$payments = Orders::payments((int) $order['id']);
$trackToken = Security::sign(['order' => (int) $order['id'], 'n' => $order['order_number']], 60 * 60 * 24 * 30);
$canCancel = in_array($order['status'], ['PENDING', 'CONFIRMED'], true);
$canReview = $order['status'] === 'DELIVERED';
$bankAccounts = (array) Config::get('payments.bank_transfer.accounts', []);
$accountActive = 'orders';

$pageTitle = 'Order ' . $order['order_number'];
$noindex = true;
require INCLUDES_PATH . '/layout/header.php';
?>
<section class="section">
  <div class="container-page">
    <?= View::breadcrumbs(['My orders' => '/account/orders.php', $order['order_number'] => null]) ?>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 mt-2">
      <div>
        <h1 class="h5 fw-bold mb-1">Order <?= e($order['order_number']) ?></h1>
        <p class="small text-ink-500 mb-0">Placed <?= e(fmt_datetime((string) $order['placed_at'])) ?> · <?= e(payment_method_label((string) $order['payment_method'])) ?></p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-soft btn-sm" href="/invoice.php?order=<?= (int) $order['id'] ?>" target="_blank" rel="noopener"><i class="bi bi-receipt"></i> Invoice</a>
        <a class="btn btn-outline-brand btn-sm" href="/contact.php?topic=order&ref=<?= rawurlencode((string) $order['order_number']) ?>"><i class="bi bi-chat-dots"></i> Get help</a>
        <?php if ($canCancel): ?>
          <form method="post" action="/actions/account.php" data-confirm="Cancel this order? Stock will be released immediately.">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="cancel_order">
            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
            <button class="btn btn-soft btn-sm text-danger" type="submit">Cancel order</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-lg-8">
        <?php require INCLUDES_PATH . '/partials/tracking-panel.php'; ?>

        <?php if ($order['payment_status'] !== 'PAID' && $order['status'] !== 'CANCELLED'): ?>
          <div class="card-soft p-3 p-md-4 mt-3">
            <h2 class="h6 fw-bold"><i class="bi bi-credit-card text-brand me-1"></i> Payment</h2>
            <p class="small text-ink-600 mb-2">Status: <?= payment_status_badge((string) $order['payment_status']) ?> · Amount due <strong><?= e(money($order['total'])) ?></strong></p>
            <?php if ($order['payment_method'] === 'BANK_TRANSFER'): ?>
              <p class="small text-ink-600"><?= e(Settings::get('bank_transfer_note')) ?></p>
              <p class="small text-danger">Upload the bank receipt within one hour of placing the order. If no receipt is uploaded, the order is automatically canceled because payment was not completed. A reference number alone is not enough.</p>
              <div class="table-responsive mb-2">
                <table class="table table-sm spec-table mb-0">
                  <thead><tr><th>Bank</th><th>Account</th><th>Name</th></tr></thead>
                  <tbody>
                  <?php foreach ($bankAccounts as $account): ?>
                    <tr><td><?= e($account['bank'] ?? '') ?></td><td class="font-monospace"><?= e($account['number'] ?? '') ?></td><td class="small"><?= e($account['name'] ?? '') ?></td></tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <form method="post" action="/actions/payment-proof.php" enctype="multipart/form-data" class="row g-2" data-disable-on-submit>
                <?= Csrf::field() ?>
                <input type="hidden" name="order_uuid" value="<?= e($order['uuid']) ?>">
                <input type="hidden" name="token" value="<?= e($trackToken) ?>">
                <div class="col-md-4"><input class="form-control form-control-sm" name="reference" placeholder="Transfer reference" maxlength="80"></div>
                <div class="col-md-5"><input class="form-control form-control-sm" type="file" name="slip" accept="image/jpeg,image/png,image/webp,application/pdf" required></div>
                <div class="col-md-3"><button class="btn btn-brand btn-sm w-100" type="submit">Upload slip</button></div>
              </form>
            <?php elseif ($order['payment_status'] === 'FAILED'): ?>
              <a class="btn btn-brand btn-sm" href="/payment/start.php?order=<?= rawurlencode((string) $order['uuid']) ?>&token=<?= rawurlencode($trackToken) ?>"><i class="bi bi-arrow-clockwise me-1"></i> Try payment again</a>
            <?php elseif (in_array($order['payment_method'], ['CARD_SEYLAN', 'KOKO', 'PAYZY', 'MINTPAY'], true)): ?>
              <a class="btn btn-brand btn-sm" href="/payment/start.php?order=<?= rawurlencode((string) $order['uuid']) ?>&token=<?= rawurlencode($trackToken) ?>"><i class="bi bi-lock me-1"></i> Pay <?= e(money($order['total'])) ?> now</a>
            <?php else: ?>
              <p class="small text-ink-500 mb-0">Pay the rider in cash when your order arrives. Please keep <?= e(money($order['total'])) ?> ready.</p>
            <?php endif; ?>
            <?php if (!empty($order['receipt_url'])): ?>
              <p class="small text-ink-500 mt-2 mb-0"><i class="bi bi-check-circle text-brand"></i> Proof submitted — awaiting verification. <a href="<?= e($order['receipt_url']) ?>" target="_blank" rel="noopener">View</a></p>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="card-soft p-3 p-md-4 mt-3">
          <h2 class="h6 fw-bold mb-2">Items</h2>
          <?php require INCLUDES_PATH . '/partials/order-items-table.php'; ?>
          <?php if ($canReview): ?>
            <a class="btn btn-outline-brand btn-sm mt-2" href="/account/reviews.php"><i class="bi bi-star me-1"></i> Review your items</a>
          <?php endif; ?>
        </div>

        <?php if ($payments !== []): ?>
          <div class="card-soft p-3 p-md-4 mt-3">
            <h2 class="h6 fw-bold mb-2">Payment history</h2>
            <div class="table-responsive">
              <table class="table table-sm table-admin mb-0">
                <thead><tr><th>When</th><th>Method</th><th>Reference</th><th>Status</th><th class="text-end">Amount</th></tr></thead>
                <tbody>
                <?php foreach ($payments as $payment): ?>
                  <tr>
                    <td class="small"><?= e(fmt_datetime((string) $payment['created_at'])) ?></td>
                    <td class="small"><?= e(humanize((string) $payment['gateway'])) ?></td>
                    <td class="small font-monospace"><?= e($payment['gateway_reference'] ?: '—') ?></td>
                    <td><?= payment_status_badge(strtoupper((string) $payment['status'])) ?></td>
                    <td class="text-end small"><?= e(money($payment['amount'])) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($events !== []): ?>
          <div class="card-soft p-3 p-md-4 mt-3">
            <h2 class="h6 fw-bold mb-2">Order history</h2>
            <ul class="list-unstyled small mb-0">
              <?php foreach (array_reverse($events) as $event): ?>
                <li class="d-flex gap-2 py-1 border-bottom">
                  <i class="bi bi-dot"></i>
                  <div class="flex-grow-1">
                    <span class="fw-semibold"><?= e(order_status_label((string) $event['status'])) ?></span> — <?= e($event['message']) ?>
                    <div class="text-ink-400" style="font-size:.72rem"><?= e(fmt_datetime((string) $event['created_at'])) ?></div>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </div>

      <div class="col-lg-4">
        <div class="card-soft p-3 mb-3">
          <h2 class="h6 fw-bold mb-2">Summary</h2>
          <div class="summary-row"><span>Subtotal</span><span><?= e(money($order['subtotal'])) ?></span></div>
          <div class="summary-row"><span>Delivery</span><span><?= $order['shipping_fee'] > 0 ? e(money($order['shipping_fee'])) : 'Free' ?></span></div>
          <?php if ($order['discount'] > 0): ?><div class="summary-row text-brand"><span>Discount</span><span>−<?= e(money($order['discount'])) ?></span></div><?php endif; ?>
          <div class="summary-row total"><span>Total</span><span><?= e(money($order['total'])) ?></span></div>
        </div>

        <div class="card-soft p-3 mb-3">
          <h2 class="h6 fw-bold mb-2">Delivery address</h2>
          <address class="small mb-0">
            <strong><?= e($order['shipping_address_array']['full_name'] ?? $order['customer_name']) ?></strong><br>
            <?= e($order['shipping_address_array']['line1'] ?? '') ?><br>
            <?= !empty($order['shipping_address_array']['line2']) ? e($order['shipping_address_array']['line2']) . '<br>' : '' ?>
            <?= e($order['shipping_address_array']['city'] ?? '') ?>, <?= e($order['shipping_address_array']['district'] ?? '') ?><br>
            <?= e(phone_display((string) $order['phone'])) ?>
          </address>
        </div>

        <?php if (!empty($order['note'])): ?>
          <div class="card-soft p-3">
            <h2 class="h6 fw-bold mb-1">Your note</h2>
            <p class="small text-ink-600 mb-0"><?= e($order['note']) ?></p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

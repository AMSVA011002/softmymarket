<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$uuid = (string) query('order', '');
$token = (string) query('token', '');
$order = $uuid !== '' ? Orders::findByUuid($uuid) : null;
if ($order === null) {
    abort(404, 'We could not find that order.');
}

$payload = $token !== '' ? Security::verify($token) : null;
$isOwner = Auth::check() && Orders::ownedBy($order, Auth::id());
$authorised = $isOwner || Auth::isTeam() || ($payload !== null && (int) ($payload['order'] ?? 0) === (int) $order['id']);
if (!$authorised) {
    abort(403, 'This order link has expired. Please sign in to view your order.');
}

$items = Orders::items((int) $order['id']);
$bankAccounts = (array) Config::get('payments.bank_transfer.accounts', []);
$gateway = PaymentManager::get((string) $order['payment_method']);
$needsPayment = $order['payment_status'] !== 'PAID' && $gateway !== null && $gateway instanceof HostedGateway;
$isBank = $order['payment_method'] === 'BANK_TRANSFER';
$trackToken = Security::sign(['order' => (int) $order['id'], 'n' => $order['order_number']], 60 * 60 * 24 * 30);
$trackUrl = '/track.php?order=' . rawurlencode((string) $order['order_number']) . '&email=' . rawurlencode((string) $order['email']);

$pageTitle = 'Order ' . $order['order_number'];
$noindex = true;
require INCLUDES_PATH . '/layout/header.php';
?>
<section class="section">
  <div class="container-page" style="max-width:980px">
    <div class="text-center mb-4">
      <span class="success-burst"><i class="bi bi-check-lg"></i></span>
      <h1 class="h4 fw-bold mt-3 mb-1">Thank you<?= $payload !== null || $isOwner ? ', ' . e(explode(' ', (string) $order['customer_name'])[0]) : '' ?>!</h1>
      <p class="text-ink-600 mb-1">Your order <strong><?= e($order['order_number']) ?></strong> has been placed.</p>
      <p class="small text-ink-500 mb-0">A confirmation email is on its way to <?= e($order['email']) ?>.</p>
    </div>

    <div class="card-soft p-3 p-md-4 mb-3">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
          <div class="text-ink-500 small">Order status</div>
          <div class="d-flex align-items-center gap-2"><?= order_status_badge((string) $order['status']) ?> <?= payment_status_badge((string) $order['payment_status']) ?></div>
        </div>
        <div class="text-end">
          <div class="text-ink-500 small">Total</div>
          <div class="h5 fw-bold mb-0"><?= e(money($order['total'])) ?></div>
        </div>
      </div>
      <div class="track-progress"><div style="width:<?= (int) $order['progress'] ?>%"></div></div>
      <div class="d-flex flex-wrap gap-2 mt-3">
        <a class="btn btn-brand btn-sm" href="<?= e($trackUrl) ?>"><i class="bi bi-truck me-1"></i> Track this order</a>
        <?php if (Auth::check()): ?><a class="btn btn-soft btn-sm" href="/account/order.php?id=<?= (int) $order['id'] ?>">View in my account</a><?php endif; ?>
        <a class="btn btn-soft btn-sm" href="/shop.php">Continue shopping</a>
      </div>
    </div>

    <?php if ($isBank && $order['payment_status'] !== 'PAID'): ?>
      <div class="card-soft p-3 p-md-4 mb-3" id="bank">
        <h2 class="h6 fw-bold mb-2"><i class="bi bi-bank text-brand me-1"></i> Complete your bank transfer</h2>
        <p class="small text-ink-600">Transfer <strong><?= e(money($order['total'])) ?></strong> using your order number <strong><?= e($order['order_number']) ?></strong> as the reference, then upload the slip below.</p>
        <div class="table-responsive mb-3">
          <table class="table table-sm spec-table mb-0">
            <thead><tr><th>Bank</th><th>Account number</th><th>Account name</th><th>Branch</th></tr></thead>
            <tbody>
            <?php foreach ($bankAccounts as $account): ?>
              <tr>
                <td class="fw-semibold"><?= e($account['bank'] ?? '') ?></td>
                <td><span class="font-monospace"><?= e($account['number'] ?? '') ?></span> <button class="btn btn-link btn-sm p-0 text-decoration-none" type="button" data-copy="<?= e($account['number'] ?? '') ?>" aria-label="Copy account number"><i class="bi bi-clipboard"></i></button></td>
                <td class="small"><?= e($account['name'] ?? '') ?></td>
                <td class="small"><?= e($account['branch'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <form method="post" action="/actions/payment-proof.php" enctype="multipart/form-data" class="row g-2" data-disable-on-submit>
          <?= Csrf::field() ?>
          <input type="hidden" name="order_uuid" value="<?= e($order['uuid']) ?>">
          <input type="hidden" name="token" value="<?= e($trackToken) ?>">
          <div class="col-md-4">
            <label class="form-label" for="reference">Transfer reference</label>
            <input class="form-control form-control-sm" id="reference" name="reference" maxlength="80" placeholder="e.g. SLIP-88213">
          </div>
          <div class="col-md-5">
            <label class="form-label" for="slip">Deposit slip (JPG, PNG or PDF, max 5MB)</label>
            <input class="form-control form-control-sm" id="slip" type="file" name="slip" accept="image/jpeg,image/png,image/webp,application/pdf">
          </div>
          <div class="col-md-3 d-flex align-items-end">
            <button class="btn btn-brand btn-sm w-100" type="submit"><i class="bi bi-upload me-1"></i> Submit proof</button>
          </div>
        </form>
        <?php if (!empty($order['receipt_url'])): ?>
          <p class="small text-ink-500 mt-2 mb-0"><i class="bi bi-check-circle text-brand"></i> Proof submitted — our finance team is verifying it.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($needsPayment): ?>
      <div class="card-soft p-3 p-md-4 mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
          <h2 class="h6 fw-bold mb-1">Finish your payment</h2>
          <p class="small text-ink-500 mb-0">Pay securely with <?= e(payment_method_label((string) $order['payment_method'])) ?>. Your order is reserved for 24 hours.</p>
        </div>
        <a class="btn btn-brand" href="/payment/start.php?order=<?= rawurlencode((string) $order['uuid']) ?>&token=<?= rawurlencode($trackToken) ?>">
          <i class="bi bi-lock me-1"></i> Pay <?= e(money($order['total'])) ?>
        </a>
      </div>
    <?php endif; ?>

    <div class="row g-3">
      <div class="col-md-7">
        <div class="card-soft p-3">
          <h2 class="h6 fw-bold mb-2">Items</h2>
          <?php require INCLUDES_PATH . '/partials/order-items-table.php'; ?>
        </div>
      </div>
      <div class="col-md-5">
        <div class="card-soft p-3 mb-3">
          <h2 class="h6 fw-bold mb-2">Delivery to</h2>
          <address class="small mb-0">
            <strong><?= e($order['shipping_address_array']['full_name'] ?? $order['customer_name']) ?></strong><br>
            <?= e($order['shipping_address_array']['line1'] ?? '') ?><?= !empty($order['shipping_address_array']['line2']) ? ', ' . e($order['shipping_address_array']['line2']) : '' ?><br>
            <?= e($order['shipping_address_array']['city'] ?? '') ?><?= !empty($order['shipping_address_array']['postal_code']) ? ' ' . e($order['shipping_address_array']['postal_code']) : '' ?><br>
            <?= e($order['shipping_address_array']['district'] ?? '') ?><br>
            <?= e(phone_display((string) $order['phone'])) ?>
          </address>
        </div>
        <div class="card-soft p-3">
          <h2 class="h6 fw-bold mb-2">Summary</h2>
          <div class="summary-row"><span>Subtotal</span><span><?= e(money($order['subtotal'])) ?></span></div>
          <div class="summary-row"><span>Delivery</span><span><?= $order['shipping_fee'] > 0 ? e(money($order['shipping_fee'])) : 'Free' ?></span></div>
          <?php if ($order['discount'] > 0): ?><div class="summary-row text-brand"><span>Discount</span><span>−<?= e(money($order['discount'])) ?></span></div><?php endif; ?>
          <div class="summary-row total"><span>Total</span><span><?= e(money($order['total'])) ?></span></div>
          <div class="summary-row"><span>Payment</span><span class="small"><?= e(payment_method_label((string) $order['payment_method'])) ?></span></div>
        </div>
      </div>
    </div>

    <div class="text-center small text-ink-500 mt-4">
      Need help? <a href="/contact.php?topic=order&ref=<?= rawurlencode((string) $order['order_number']) ?>">Contact support</a> or call <?= e(Settings::get('support_phone')) ?>.
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

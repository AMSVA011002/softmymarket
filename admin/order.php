<?php
/**
 * Single order workspace: status, tracking, payments, notes and timeline.
 */
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
admin_require('orders.view');

$order = Orders::find(query_int('id'));
if ($order === null) {
    flash('danger', 'Order not found.');
    redirect(admin_url('orders.php'));
}

$items = Orders::items((int) $order['id']);
$events = Orders::events((int) $order['id']);
$payments = Orders::payments((int) $order['id']);
$customer = $order['user_id'] !== null ? Users::find((int) $order['user_id']) : null;
$shipping = $order['shipping_address_array'];
$billing = $order['billing_address_array'];
$returnUrl = admin_url('order.php', ['id' => (int) $order['id']]);
$allowed = Orders::TRANSITIONS[(string) $order['status']] ?? [];
$paymentStatus = strtoupper((string) $order['payment_status']);
$orderNumber = (string) $order['order_number'];

$adminTitle = 'Order ' . $order['order_number'];
$adminSubtitle = 'Placed ' . fmt_datetime((string) $order['placed_at']) . ' · ' . payment_method_label((string) $order['payment_method']);
$adminActions = '<a class="btn btn-sm btn-soft" href="' . e('/invoice.php?order=' . (int) $order['id']) . '" target="_blank" rel="noopener"><i class="bi bi-printer me-1"></i> Invoice</a>'
    . '<a class="btn btn-sm btn-soft" href="' . e(admin_url('orders.php')) . '"><i class="bi bi-arrow-left me-1"></i> All orders</a>';
require __DIR__ . '/includes/layout-top.php';
?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card-soft p-3 mb-3">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
          <div class="d-flex align-items-center gap-2 mb-1">
            <?= order_status_badge((string) $order['status']) ?>
            <?= payment_status_badge($paymentStatus) ?>
            <?php if ($order['is_open']): ?><span class="badge text-bg-light">Open</span><?php endif; ?>
          </div>
          <p class="small text-ink-500 mb-0">Last update <?= e(time_ago((string) $order['updated_at'])) ?> · <?= e((string) $order['order_number']) ?></p>
        </div>
        <div class="text-end">
          <div class="small text-ink-500">Order total</div>
          <div class="h5 fw-bold mb-0"><?= e(money($order['total'])) ?></div>
        </div>
      </div>

      <div class="progress mb-1" style="height:6px">
        <div class="progress-bar bg-success" style="width:<?= Orders::progress((string) $order['status']) ?>%"></div>
      </div>
      <div class="d-flex justify-content-between small text-ink-400">
        <?php foreach (order_statuses_flow() as $step): ?>
          <span class="<?= Orders::progress((string) $order['status']) >= Orders::progress($step) ? 'text-brand fw-semibold' : '' ?>"><?= e(order_status_label($step)) ?></span>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card-soft p-0 mb-3">
      <div class="px-3 pt-3 pb-2"><h2 class="h6 fw-bold mb-0">Items (<?= count($items) ?>)</h2></div>
      <div class="table-responsive">
        <table class="table table-admin mb-0">
          <thead><tr><th>Product</th><th>SKU</th><th class="text-center">Qty</th><th class="text-end">Unit</th><th class="text-end">Total</th></tr></thead>
          <tbody>
            <?php foreach ($items as $item): ?>
              <tr>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <img src="<?= e(img_url($item['image_url'] ?? null, (string) $item['title'], 80)) ?>" alt="" width="36" height="36" style="border-radius:.4rem;object-fit:cover">
                    <div class="min-w-0">
                      <div class="text-truncate"><?= e((string) $item['title']) ?></div>
                      <?php if (!empty($item['product_id'])): ?>
                        <a class="small" href="<?= e(admin_url('product-form.php', ['id' => (int) $item['product_id']])) ?>">Edit product</a>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td class="text-ink-500"><?= e($item['sku'] ?? '—') ?></td>
                <td class="text-center"><?= (int) $item['quantity'] ?></td>
                <td class="text-end"><?= e(money($item['unit_price'])) ?></td>
                <td class="text-end fw-semibold"><?= e(money($item['total'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="p-3 border-top">
        <div class="summary-row"><span>Subtotal</span><span><?= e(money($order['subtotal'])) ?></span></div>
        <?php if ((float) $order['discount'] > 0): ?><div class="summary-row text-brand"><span>Discount<?= $order['coupon_code'] ? ' (' . e((string) $order['coupon_code']) . ')' : '' ?></span><span>−<?= e(money($order['discount'])) ?></span></div><?php endif; ?>
        <div class="summary-row"><span>Shipping</span><span><?= (float) $order['shipping_fee'] > 0 ? e(money($order['shipping_fee'])) : 'Free' ?></span></div>
        <?php if ((float) $order['tax'] > 0): ?><div class="summary-row"><span>Tax</span><span><?= e(money($order['tax'])) ?></span></div><?php endif; ?>
        <div class="summary-row total"><span>Total</span><span><?= e(money($order['total'])) ?></span></div>
      </div>
    </div>

    <div class="card-soft p-3 mb-3">
      <h2 class="h6 fw-bold mb-2">Timeline</h2>
      <form method="post" action="/admin/actions.php" class="row g-2 mb-3">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="order_note">
        <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
        <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
        <div class="col-12">
          <label class="form-label small" for="order-note">Add a note to the timeline</label>
          <textarea class="form-control form-control-sm" id="order-note" name="message" rows="2" maxlength="500" placeholder="e.g. Customer called — deliver after 5pm." required></textarea>
        </div>
        <div class="col-12 d-flex align-items-center justify-content-between">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="note-public" name="is_public" value="1" checked>
            <label class="form-check-label small" for="note-public">Visible to the customer</label>
          </div>
          <button class="btn btn-sm btn-brand" type="submit">Add note</button>
        </div>
      </form>
      <div class="track-timeline">
        <?php foreach ($events as $event): ?>
          <div class="track-step <?= ($event['status'] ?? '') === $order['status'] ? 'current' : 'done' ?>">
            <h4><?= e(order_status_label((string) ($event['status'] ?? ''))) ?> <?php if (!(int) ($event['is_public'] ?? 1)): ?><span class="badge text-bg-light">internal</span><?php endif; ?></h4>
            <p><?= e((string) ($event['message'] ?? '')) ?></p>
            <time><?= e(fmt_datetime((string) ($event['created_at'] ?? ''))) ?> · <?= e(humanize((string) ($event['actor_type'] ?? 'system'))) ?></time>
          </div>
        <?php endforeach; ?>
        <?php if ($events === []): ?><p class="small text-ink-500 mb-0">No events recorded yet.</p><?php endif; ?>
      </div>
    </div>

    <div class="card-soft p-3">
      <h2 class="h6 fw-bold mb-2">Payments</h2>
      <div class="table-responsive">
        <table class="table table-admin mb-0">
          <thead><tr><th>Gateway</th><th>Reference</th><th class="text-end">Amount</th><th>Status</th><th>When</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($payments as $payment): $pstatus = strtolower((string) $payment['status']); ?>
              <tr>
                <td><?= e(payment_method_label(PaymentManager::methodCode((string) $payment['gateway']))) ?><div class="text-ink-400" style="font-size:.68rem"><?= e((string) $payment['gateway']) ?></div></td>
                <td class="text-ink-500 small"><?= e((string) ($payment['gateway_reference'] ?? '—')) ?>
                  <?php if (!empty($payment['receipt_url'])): ?><br><a class="small" href="<?= e((string) $payment['receipt_url']) ?>" target="_blank" rel="noopener"><i class="bi bi-paperclip"></i> slip</a><?php endif; ?>
                </td>
                <td class="text-end"><?= e(money($payment['amount'])) ?></td>
                <td><span class="badge text-bg-<?= $pstatus === 'paid' ? 'success' : ($pstatus === 'failed' ? 'danger' : 'warning') ?>"><?= e(humanize($pstatus)) ?></span></td>
                <td class="small text-ink-500"><?= e(fmt_datetime((string) $payment['created_at'])) ?></td>
                <td class="text-end">
                  <?php if ($pstatus !== 'paid' && Auth::can('payments.verify')): ?>
                    <form method="post" action="/admin/actions.php" class="d-inline">
                      <?= Csrf::field() ?><input type="hidden" name="action" value="payment_mark_paid">
                      <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                      <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                      <button class="btn btn-sm btn-brand" type="submit">Mark paid</button>
                    </form>
                    <form method="post" action="/admin/actions.php" class="d-inline">
                      <?= Csrf::field() ?><input type="hidden" name="action" value="payment_fail">
                      <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                      <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                      <button class="btn btn-sm btn-soft" type="submit">Fail</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if ($payments === []): ?><tr><td colspan="6" class="small text-ink-500 py-3">No payment attempts recorded.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <?php if (Auth::can('orders.manage')): ?>
      <div class="card-soft p-3 mb-3">
        <h2 class="h6 fw-bold mb-2">Update status</h2>
        <?php if ($allowed !== []): ?>
          <form method="post" action="/admin/actions.php" data-disable-on-submit>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="order_status">
            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
            <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
            <label class="form-label small" for="new-status">New status</label>
            <select class="form-select form-select-sm mb-2" id="new-status" name="status" required>
              <?php foreach ($allowed as $next): ?>
                <option value="<?= e($next) ?>"><?= e(order_status_label($next)) ?></option>
              <?php endforeach; ?>
            </select>
            <label class="form-label small" for="status-message">Message to customer (optional)</label>
            <textarea class="form-control form-control-sm mb-2" id="status-message" name="message" rows="2" maxlength="400"></textarea>
            <button class="btn btn-brand btn-sm w-100" type="submit">Save status</button>
          </form>
        <?php else: ?>
          <p class="small text-ink-500 mb-0">This order is in a final state (<?= e(order_status_label((string) $order['status'])) ?>).</p>
        <?php endif; ?>

        <?php if (Auth::can('settings.manage') && !in_array($order['status'], ['REFUNDED', 'CANCELLED'], true)): ?>
          <hr>
          <form method="post" action="/admin/actions.php" data-confirm="Refund this order? This marks it refunded and cannot be undone.">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="order_refund">
            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
            <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
            <button class="btn btn-outline-danger btn-sm w-100" type="submit"><i class="bi bi-arrow-counterclockwise me-1"></i> Mark refunded</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="card-soft p-3 mb-3">
        <h2 class="h6 fw-bold mb-2">Delivery tracking</h2>
        <form method="post" action="/admin/actions.php">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="order_tracking">
          <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
          <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
          <label class="form-label small" for="tracking-number">Tracking number</label>
          <input class="form-control form-control-sm mb-2" id="tracking-number" name="tracking_number" value="<?= e((string) ($order['tracking_number'] ?? '')) ?>">
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label small" for="carrier">Carrier</label>
              <input class="form-control form-control-sm" id="carrier" name="carrier" value="<?= e((string) ($order['carrier'] ?? '')) ?>" placeholder="Pronto, Domex…">
            </div>
            <div class="col-6">
              <label class="form-label small" for="courier-url">Tracking link</label>
              <input class="form-control form-control-sm" id="courier-url" name="courier_url" value="<?= e((string) ($order['courier_url'] ?? '')) ?>">
            </div>
          </div>
          <label class="form-label small" for="tracking-message">Note</label>
          <input class="form-control form-control-sm mb-2" id="tracking-message" name="message" placeholder="Handed to courier" maxlength="200">
          <button class="btn btn-ocean btn-sm w-100" type="submit">Save tracking</button>
        </form>
      </div>
    <?php endif; ?>

    <div class="card-soft p-3 mb-3">
      <h2 class="h6 fw-bold mb-2">Customer</h2>
      <p class="small mb-1 fw-semibold"><?= e((string) $order['customer_name']) ?></p>
      <p class="small text-ink-500 mb-1"><i class="bi bi-telephone me-1"></i><a class="text-decoration-none" href="tel:<?= e((string) $order['phone']) ?>"><?= e(phone_display((string) $order['phone'])) ?></a></p>
      <p class="small text-ink-500 mb-2"><i class="bi bi-envelope me-1"></i><a class="text-decoration-none" href="mailto:<?= e((string) $order['email']) ?>"><?= e((string) $order['email']) ?></a></p>
      <?php if ($customer !== null): ?>
        <a class="btn btn-sm btn-soft w-100" href="<?= e(admin_url('customer.php', ['id' => (int) $customer['id']])) ?>"><i class="bi bi-person me-1"></i> View profile</a>
      <?php else: ?>
        <span class="badge text-bg-light">Guest checkout</span>
      <?php endif; ?>
    </div>

    <div class="card-soft p-3 mb-3">
      <h2 class="h6 fw-bold mb-2">Delivery address</h2>
      <address class="small text-ink-600 mb-0">
        <?= e((string) ($shipping['full_name'] ?? $order['customer_name'])) ?><br>
        <?= e((string) ($shipping['line1'] ?? '')) ?><?php if (!empty($shipping['line2'])): ?><br><?= e((string) $shipping['line2']) ?><?php endif; ?><br>
        <?= e((string) ($shipping['city'] ?? '')) ?><?php if (!empty($shipping['district'])): ?>, <?= e((string) $shipping['district']) ?><?php endif; ?><br>
        <?= e(phone_display((string) ($shipping['phone'] ?? $order['phone']))) ?>
      </address>
      <?php if ($billing !== [] && $billing !== $shipping): ?>
        <hr>
        <h3 class="h6 fw-bold mb-2">Billing address</h3>
        <address class="small text-ink-600 mb-0">
          <?= e((string) ($billing['full_name'] ?? '')) ?><br>
          <?= e((string) ($billing['line1'] ?? '')) ?><br>
          <?= e((string) ($billing['city'] ?? '')) ?>
        </address>
      <?php endif; ?>
    </div>

    <div class="card-soft p-3">
      <h2 class="h6 fw-bold mb-2">Order facts</h2>
      <dl class="row small mb-0">
        <dt class="col-6 text-ink-500 fw-normal">Payment method</dt><dd class="col-6 text-end"><?= e(payment_method_label((string) $order['payment_method'])) ?></dd>
        <dt class="col-6 text-ink-500 fw-normal">Coupon</dt><dd class="col-6 text-end"><?= e((string) ($order['coupon_code'] ?? '—')) ?></dd>
        <dt class="col-6 text-ink-500 fw-normal">IP address</dt><dd class="col-6 text-end"><?= e((string) ($order['ip_address'] ?? '—')) ?></dd>
        <dt class="col-6 text-ink-500 fw-normal">Placed</dt><dd class="col-6 text-end"><?= e(fmt_date((string) $order['placed_at'])) ?></dd>
        <dt class="col-6 text-ink-500 fw-normal">Customer note</dt><dd class="col-6 text-end"><?= e((string) ($order['note'] ?? '—')) ?></dd>
      </dl>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>

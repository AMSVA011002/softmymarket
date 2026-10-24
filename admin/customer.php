<?php
/**
 * Customer profile: lifetime value, order history, addresses and account state.
 */
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
admin_require('customers.view');

$customer = Users::find(query_int('id'));
if ($customer === null) {
    flash('danger', 'Customer not found.');
    redirect(admin_url('customers.php'));
}
$stats = Users::stats((int) $customer['id']);
$orders = Orders::forUser((int) $customer['id'], query_int('page', 1), 10);
$addresses = Addresses::forUser((int) $customer['id']);
$notifications = Notifier::recent((int) $customer['id'], 6);
$returnUrl = admin_url('customer.php', ['id' => (int) $customer['id']]);
$isTeam = in_array((string) $customer['role'], ['admin', 'staff'], true);

$adminTitle = $customer['name'];
$adminSubtitle = role_label((string) $customer['role']) . ' · joined ' . fmt_date((string) $customer['created_at']);
$adminActions = '<a class="btn btn-sm btn-soft" href="' . e(admin_url('customers.php')) . '"><i class="bi bi-arrow-left me-1"></i> All customers</a>'
    . '<a class="btn btn-sm btn-soft" href="mailto:' . e((string) $customer['email']) . '"><i class="bi bi-envelope me-1"></i> Email</a>';
require __DIR__ . '/includes/layout-top.php';
?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="row g-2 g-md-3 mb-3">
      <?php
      $tiles = [
          ['Orders', (string) (int) ($stats['orders_count'] ?? 0), 'bi-box-seam'],
          ['Lifetime spend', money((float) ($stats['total_spent'] ?? 0)), 'bi-cash-stack'],
          ['Average order', money((float) ($stats['avg_order'] ?? 0)), 'bi-receipt'],
          ['Last order', !empty($stats['last_order_at']) ? fmt_date((string) $stats['last_order_at']) : '—', 'bi-clock-history'],
      ];
      foreach ($tiles as [$label, $value, $icon]): ?>
        <div class="col-6 col-md-3">
          <div class="kpi-card h-100">
            <div class="kpi-label"><?= e($label) ?></div>
            <div class="kpi-value" style="font-size:1.1rem"><?= e($value) ?></div>
            <i class="bi <?= e($icon) ?> text-ink-300"></i>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card-soft p-0 mb-3">
      <div class="d-flex align-items-center justify-content-between px-3 pt-3 pb-2">
        <h2 class="h6 fw-bold mb-0">Orders</h2>
        <span class="small text-ink-400"><?= number_format((int) $orders['total']) ?> total</span>
      </div>
      <div class="table-responsive">
        <table class="table table-admin mb-0">
          <thead><tr><th>Order</th><th>Date</th><th class="text-end">Total</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($orders['rows'] as $order): ?>
              <tr>
                <td><a class="text-decoration-none" href="<?= e(admin_url('order.php', ['id' => (int) $order['id']])) ?>"><?= e((string) $order['order_number']) ?></a></td>
                <td class="small text-ink-500"><?= e(fmt_date((string) $order['placed_at'])) ?></td>
                <td class="text-end small"><?= e(money((float) $order['total'])) ?></td>
                <td><?= order_status_badge((string) $order['status']) ?></td>
                <td class="text-end"><a class="btn btn-sm btn-soft" href="<?= e(admin_url('order.php', ['id' => (int) $order['id']])) ?>"><i class="bi bi-chevron-right"></i></a></td>
              </tr>
            <?php endforeach; ?>
            <?php if ($orders['rows'] === []): ?><tr><td colspan="5" class="text-center small text-ink-500 py-4">No orders yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ((int) $orders['pager']['pages'] > 1): ?>
        <div class="p-2"><?= pagination_links($orders['pager'], ['id' => (int) $customer['id']]) ?></div>
      <?php endif; ?>
    </div>

    <div class="card-soft p-3">
      <h2 class="h6 fw-bold mb-2">Address book</h2>
      <div class="row g-2">
        <?php foreach ($addresses as $address): ?>
          <div class="col-md-6">
            <div class="p-2 rounded small" style="border:1px solid var(--ink-200)">
              <strong><?= e($address['full_name']) ?></strong>
              <?php if (!empty($address['label'])): ?><span class="badge text-bg-light ms-1"><?= e($address['label']) ?></span><?php endif; ?>
              <div class="text-ink-600"><?= e($address['line1']) ?><?= !empty($address['line2']) ? ', ' . e($address['line2']) : '' ?><br><?= e($address['city']) ?><?= !empty($address['district']) ? ', ' . e($address['district']) : '' ?></div>
              <div class="text-ink-400"><?= e(phone_display((string) $address['phone'])) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if ($addresses === []): ?><p class="small text-ink-500 mb-0">No saved addresses.</p><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card-soft p-3 mb-3">
      <h2 class="h6 fw-bold mb-2">Account</h2>
      <dl class="row small mb-0">
        <dt class="col-5 text-ink-500 fw-normal">Email</dt><dd class="col-7 text-end text-break"><?= e((string) $customer['email']) ?></dd>
        <dt class="col-5 text-ink-500 fw-normal">Phone</dt><dd class="col-7 text-end"><?= e(phone_display((string) ($customer['phone'] ?? '')) ?: '—') ?></dd>
        <dt class="col-5 text-ink-500 fw-normal">Marketing</dt><dd class="col-7 text-end"><?= (int) $customer['marketing_opt_in'] === 1 ? 'Opted in' : 'Not subscribed' ?></dd>
        <dt class="col-5 text-ink-500 fw-normal">Last sign-in</dt><dd class="col-7 text-end"><?= !empty($customer['last_login_at']) ? e(time_ago((string) $customer['last_login_at'])) : 'Never' ?></dd>
        <dt class="col-5 text-ink-500 fw-normal">Status</dt><dd class="col-7 text-end"><span class="badge text-bg-<?= $customer['status'] === 'active' ? 'success' : 'secondary' ?>"><?= e(humanize((string) $customer['status'])) ?></span></dd>
      </dl>

      <?php if (Auth::can('customers.manage') && (int) $customer['id'] !== (int) Auth::id()): ?>
        <hr>
        <form method="post" action="/admin/actions.php" class="d-grid gap-2" data-confirm="Change this account's status?">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="customer_status">
          <input type="hidden" name="user_id" value="<?= (int) $customer['id'] ?>">
          <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
          <input type="hidden" name="status" value="<?= $customer['status'] === 'active' ? 'suspended' : 'active' ?>">
          <button class="btn btn-sm <?= $customer['status'] === 'active' ? 'btn-outline-danger' : 'btn-brand' ?>" type="submit">
            <i class="bi <?= $customer['status'] === 'active' ? 'bi-slash-circle' : 'bi-check2-circle' ?> me-1"></i>
            <?= $customer['status'] === 'active' ? 'Suspend account' : 'Reactivate account' ?>
          </button>
        </form>
      <?php endif; ?>

      <?php if (Auth::can('users.manage') && (int) $customer['id'] !== (int) Auth::id()): ?>
        <form method="post" action="/admin/actions.php" class="mt-2">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="customer_role">
          <input type="hidden" name="user_id" value="<?= (int) $customer['id'] ?>">
          <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
          <label class="form-label small" for="c-role">Role</label>
          <div class="d-flex gap-1">
            <select class="form-select form-select-sm" id="c-role" name="role">
              <?php foreach (['customer' => 'Customer', 'staff' => 'Store staff', 'admin' => 'Administrator'] as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $customer['role'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-soft text-nowrap" type="submit">Save</button>
          </div>
        </form>
      <?php endif; ?>
    </div>

    <div class="card-soft p-3">
      <h2 class="h6 fw-bold mb-2">Recent notifications</h2>
      <ul class="list-unstyled small mb-0">
        <?php foreach ($notifications as $notification): ?>
          <li class="py-1 border-bottom">
            <div class="fw-semibold text-truncate"><?= e((string) $notification['title']) ?></div>
            <div class="text-ink-400" style="font-size:.68rem"><?= e(time_ago((string) $notification['created_at'])) ?></div>
          </li>
        <?php endforeach; ?>
        <?php if ($notifications === []): ?><li class="text-ink-500">Nothing yet.</li><?php endif; ?>
      </ul>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>

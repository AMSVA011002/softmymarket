<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
Auth::requireLogin();

$user = Auth::user();
$stats = Users::stats((int) $user['id']);
$orders = Orders::forUser((int) $user['id'], 1, 5);
$wishlist = Catalog::productsByIds(Cart::wishlistIds());
$notifications = Notifier::recent((int) $user['id'], 5);
$address = Addresses::forUser((int) $user['id'])[0] ?? null;
$accountActive = 'index';

$pageTitle = 'My account';
$noindex = true;
require INCLUDES_PATH . '/layout/header.php';
?>
<section class="section">
  <div class="container-page">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
      <div>
        <h1 class="h4 fw-bold mb-1">Hello, <?= e(explode(' ', (string) $user['name'])[0]) ?> 👋</h1>
        <p class="small text-ink-500 mb-0">Member since <?= e(fmt_date((string) $user['created_at'], 'F Y')) ?> · <?= e($user['email']) ?></p>
      </div>
      <div class="d-flex gap-2">
        <?php if (Auth::isTeam()): ?><a class="btn btn-outline-brand btn-sm" href="/admin/index.php"><i class="bi bi-grid-1x2 me-1"></i>Dashboard</a><?php endif; ?>
        <a class="btn btn-brand btn-sm" href="/shop.php"><i class="bi bi-bag me-1"></i>Shop</a>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-lg-3"><?php require INCLUDES_PATH . '/account-nav.php'; ?></div>
      <div class="col-lg-9">
        <div class="row g-2 g-md-3 mb-3">
          <div class="col-6 col-xl-3">
            <div class="kpi-card"><div class="kpi-icon bg-brand-soft"><i class="bi bi-box-seam"></i></div><div><div class="kpi-value"><?= (int) ($stats['orders_count'] ?? 0) ?></div><div class="kpi-label">Orders placed</div></div></div>
          </div>
          <div class="col-6 col-xl-3">
            <div class="kpi-card"><div class="kpi-icon bg-ocean-soft"><i class="bi bi-cash-stack"></i></div><div><div class="kpi-value"><?= e(money($stats['total_spent'] ?? 0)) ?></div><div class="kpi-label">Lifetime spend</div></div></div>
          </div>
          <div class="col-6 col-xl-3">
            <div class="kpi-card"><div class="kpi-icon bg-brand-soft"><i class="bi bi-heart"></i></div><div><div class="kpi-value"><?= count($wishlist) ?></div><div class="kpi-label">Wishlist items</div></div></div>
          </div>
          <div class="col-6 col-xl-3">
            <div class="kpi-card"><div class="kpi-icon bg-ocean-soft"><i class="bi bi-bell"></i></div><div><div class="kpi-value"><?= (int) $accountUnread ?? 0 ?></div><div class="kpi-label">Unread alerts</div></div></div>
          </div>
        </div>

        <div class="card-soft p-3 p-md-4 mb-3">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h2 class="h6 fw-bold mb-0">Recent orders</h2>
            <a class="small text-decoration-none" href="/account/orders.php">View all <i class="bi bi-arrow-right"></i></a>
          </div>
          <?php if ($orders['rows'] === []): ?>
            <?= View::emptyState('bi-box', 'No orders yet', 'When you place an order it will appear here with live tracking.', 'Start shopping', '/shop.php') ?>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-admin align-middle mb-0">
                <thead><tr><th>Order</th><th>Placed</th><th>Status</th><th class="text-end">Total</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($orders['rows'] as $order): ?>
                  <tr>
                    <td class="fw-semibold"><?= e($order['order_number']) ?></td>
                    <td class="small text-ink-500"><?= e(fmt_date((string) $order['placed_at'])) ?></td>
                    <td><?= order_status_badge((string) $order['status']) ?></td>
                    <td class="text-end fw-semibold small"><?= e(money($order['total'])) ?></td>
                    <td class="text-end"><a class="btn btn-soft btn-sm" href="/account/order.php?id=<?= (int) $order['id'] ?>">View</a></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <div class="row g-3">
          <div class="col-md-6">
            <div class="card-soft p-3 h-100">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="h6 fw-bold mb-0">Default address</h2>
                <a class="small text-decoration-none" href="/account/addresses.php">Manage</a>
              </div>
              <?php if ($address === null): ?>
                <p class="small text-ink-500 mb-0">No saved address yet — add one to check out faster.</p>
              <?php else: ?>
                <address class="small mb-0">
                  <strong><?= e($address['full_name']) ?></strong><br>
                  <?= e($address['line1']) ?><?= $address['line2'] ? ', ' . e($address['line2']) : '' ?><br>
                  <?= e($address['city']) ?>, <?= e($address['district']) ?> <?= e($address['postal_code']) ?><br>
                  <?= e(phone_display((string) $address['phone'])) ?>
                </address>
              <?php endif; ?>
            </div>
          </div>
          <div class="col-md-6">
            <div class="card-soft p-3 h-100">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="h6 fw-bold mb-0">Notifications</h2>
                <a class="small text-decoration-none" href="/account/notifications.php">All</a>
              </div>
              <?php if ($notifications === []): ?>
                <p class="small text-ink-500 mb-0">Nothing new right now.</p>
              <?php else: ?>
                <ul class="list-unstyled small mb-0">
                  <?php foreach (array_slice($notifications, 0, 3) as $note): ?>
                    <li class="d-flex gap-2 mb-2">
                      <i class="bi bi-dot"></i>
                      <div><div class="fw-semibold"><?= e(limit_text((string) $note['title'], 48)) ?></div><div class="text-ink-500" style="font-size:.72rem"><?= e(time_ago((string) $note['created_at'])) ?></div></div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

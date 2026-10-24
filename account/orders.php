<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
Auth::requireLogin();

$page = query_int('page', 1, 1, 1000);
$result = Orders::forUser((int) Auth::id(), $page, 10);
$counts = Orders::itemCounts(array_map(static fn ($row) => (int) $row['id'], $result['rows']));
$accountActive = 'orders';

$pageTitle = 'My orders';
$noindex = true;
require INCLUDES_PATH . '/layout/header.php';
?>
<section class="section">
  <div class="container-page">
    <h1 class="h4 fw-bold mb-3">My orders</h1>
    <div class="row g-4">
      <div class="col-lg-3"><?php require INCLUDES_PATH . '/account-nav.php'; ?></div>
      <div class="col-lg-9">
        <?php if ($result['rows'] === []): ?>
          <?= View::emptyState('bi-box-seam', 'No orders yet', 'Your orders and their tracking will show up here.', 'Start shopping', '/shop.php') ?>
        <?php else: ?>
          <?php foreach ($result['rows'] as $order): ?>
            <div class="card-soft p-3 mb-2">
              <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                  <div class="d-flex align-items-center gap-2 mb-1">
                    <a class="fw-bold text-decoration-none" href="/account/order.php?id=<?= (int) $order['id'] ?>"><?= e($order['order_number']) ?></a>
                    <?= order_status_badge((string) $order['status']) ?>
                    <?= payment_status_badge((string) $order['payment_status']) ?>
                  </div>
                  <div class="small text-ink-500">Placed <?= e(fmt_datetime((string) $order['placed_at'])) ?> · <?= e(payment_method_label((string) $order['payment_method'])) ?> · <?= (int) ($counts[(int) $order['id']] ?? 0) ?> item<?= (int) ($counts[(int) $order['id']] ?? 0) === 1 ? '' : 's' ?></div>
                </div>
                <div class="text-end">
                  <div class="fw-bold"><?= e(money($order['total'])) ?></div>
                  <a class="small text-decoration-none" href="/account/order.php?id=<?= (int) $order['id'] ?>">Details <i class="bi bi-arrow-right"></i></a>
                </div>
              </div>
              <div class="track-progress mt-2"><div style="width:<?= (int) $order['progress'] ?>%"></div></div>
            </div>
          <?php endforeach; ?>
          <div class="mt-3"><?= pagination_links($result['pager'], []) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

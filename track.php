<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$orderNumber = trim((string) (query('order') ?? ''));
$contact = trim((string) (query('contact') ?? query('email') ?? ''));
$snapshot = null;
$error = null;

if ($orderNumber !== '' && $contact !== '') {
    $lock = RateLimiter::check('track', Security::clientIp());
    if ($lock['blocked']) {
        $error = 'Too many lookups. Please wait a few minutes and try again.';
    } else {
        RateLimiter::hit('track', Security::clientIp());
        $snapshot = OrderService::publicLookup($orderNumber, $contact);
        if ($snapshot === null) {
            $error = 'We could not find an order with those details. Check the order number and use the email or mobile on the order.';
        }
    }
}

$pageTitle = 'Track your order';
$pageDescription = 'Follow your ' . Settings::get('store_name') . ' order from confirmation to delivery.';
$noindex = true;
require INCLUDES_PATH . '/layout/header.php';
?>
<div class="breadcrumb-wrap"><div class="container-page"><?= View::breadcrumbs(['Track order' => null]) ?></div></div>

<section class="section pt-2">
  <div class="container-page" style="max-width:820px">
    <div class="text-center mb-4">
      <h1 class="h4 fw-bold mb-1">Track your order</h1>
      <p class="small text-ink-600 mb-0">Enter your order number and the email or mobile number used at checkout.</p>
    </div>

    <?php if ($error !== null): ?>
      <div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i><?= e($error) ?></div>
    <?php endif; ?>

    <div class="card-soft p-3 p-md-4 mb-4">
      <form method="get" action="/track.php" class="row g-2 align-items-end">
        <div class="col-md-5">
          <label class="form-label" for="order">Order number</label>
          <input class="form-control" id="order" name="order" required value="<?= e($orderNumber) ?>" placeholder="SMM-2026-000123">
        </div>
        <div class="col-md-5">
          <label class="form-label" for="contact">Email or mobile</label>
          <input class="form-control" id="contact" name="contact" required value="<?= e($contact) ?>" placeholder="you@email.com or 0771234567">
        </div>
        <div class="col-md-2 d-grid">
          <button class="btn btn-brand" type="submit">Track</button>
        </div>
      </form>
      <p class="small text-ink-400 mt-2 mb-0"><i class="bi bi-shield-lock me-1"></i> For your privacy we require both pieces of information.</p>
    </div>

    <?php if ($snapshot !== null): ?>
      <?= View::flashes() ?>
      <div class="card-soft p-3 p-md-4 mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
          <div>
            <div class="small text-ink-500">Order</div>
            <h2 class="h5 fw-bold mb-1"><?= e($snapshot['order_number']) ?></h2>
            <div class="small text-ink-500">Placed <?= e(fmt_datetime((string) $snapshot['placed_at'])) ?> · <?= e(payment_method_label((string) $snapshot['payment_method'])) ?></div>
          </div>
          <div class="text-end">
            <?= order_status_badge((string) $snapshot['status']) ?>
            <div class="small text-ink-500 mt-1">Total <?= e(money($snapshot['total'])) ?></div>
          </div>
        </div>
        <div class="track-progress mt-3"><div style="width:<?= (int) $snapshot['progress'] ?>%"></div></div>
        <div class="d-flex justify-content-between small text-ink-500 mt-1">
          <span><?= e(order_status_label('PENDING')) ?></span>
          <span><?= e(order_status_label('DELIVERED')) ?></span>
        </div>
      </div>

      <?php $order = $snapshot; $trackToken = Security::sign(['order' => (int) $snapshot['id'], 'n' => $snapshot['order_number']], 3600); ?>
      <div class="card-soft p-3 p-md-4">
        <h2 class="h6 fw-bold mb-3">Timeline</h2>
        <div class="track-timeline" data-track-timeline data-count="<?= count($snapshot['events']) ?>">
          <?php foreach ($snapshot['events'] as $index => $event): ?>
            <div class="track-step <?= $index === count($snapshot['events']) - 1 ? 'current' : 'done' ?>">
              <h4><?= e(order_status_label((string) $event['status'])) ?></h4>
              <p><?= e($event['message']) ?></p>
              <time><?= e(fmt_datetime((string) $event['at'])) ?></time>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php elseif ($orderNumber === ''): ?>
      <div class="card-soft p-3 p-md-4">
        <h2 class="h6 fw-bold mb-2">Where is my order number?</h2>
        <p class="small text-ink-600 mb-0">It is in your confirmation email and looks like <span class="font-monospace">SMM-2026-000123</span>. If you have an account you can also find it under <a href="/account/orders.php">My orders</a>.</p>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

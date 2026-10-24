<?php
/**
 * Live tracking panel.
 *
 * @var array<string,mixed> $order
 * @var list<array<string,mixed>> $events
 * @var string $trackToken signed token for the poller
 * @var int $refreshSeconds
 */
$events = $events ?? [];
$refreshSeconds = $refreshSeconds ?? (int) Settings::int('live_tracking_refresh', 15);
$flow = order_statuses_flow();
$currentIndex = array_search($order['status'], $flow, true);
if ($currentIndex === false) {
    $currentIndex = count($flow) - 1;
}
?>
<div class="card-soft p-3 p-md-4" data-track-order="<?= (int) $order['id'] ?>" data-track-token="<?= e($trackToken) ?>" data-track-interval="<?= $refreshSeconds ?>">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h2 class="h6 fw-bold mb-0">Order <?= e($order['order_number']) ?></h2>
        <span class="status-pill live" data-track-pill><span class="dot"></span><span data-track-status><?= e(order_status_label((string) $order['status'])) ?></span></span>
      </div>
      <p class="small text-ink-500 mb-0">
        Last update <span data-track-updated><?= e(time_ago((string) $order['updated_at'])) ?></span>
        · refreshes automatically every <?= $refreshSeconds ?>s
      </p>
    </div>
    <div class="text-end small">
      <?= payment_status_badge((string) $order['payment_status']) ?>
      <div class="text-ink-500 mt-1"><?= e(payment_method_label((string) $order['payment_method'])) ?></div>
    </div>
  </div>

  <div class="track-progress mb-3"><div style="width:<?= (int) $order['progress'] ?>%" data-track-progress></div></div>

  <div class="d-flex justify-content-between small text-ink-500 mb-3" style="font-size:.68rem">
    <?php foreach ($flow as $index => $status): ?>
      <span class="text-center flex-fill <?= $index <= $currentIndex ? 'text-brand fw-semibold' : '' ?>" style="min-width:0">
        <i class="bi <?= $index <= $currentIndex ? 'bi-check-circle-fill' : 'bi-circle' ?> d-block"></i>
        <span class="d-none d-md-block"><?= e(order_status_label($status)) ?></span>
      </span>
    <?php endforeach; ?>
  </div>

  <div class="d-none" data-track-courier>
    <div class="alert alert-light border small mb-3">
      <i class="bi bi-box-seam text-brand me-1"></i>
      <strong data-track-carrier>Courier</strong> · tracking <span class="font-monospace" data-track-number></span>
      <a class="ms-2 fw-semibold d-none" data-track-link target="_blank" rel="noopener noreferrer nofollow">Track with courier <i class="bi bi-box-arrow-up-right"></i></a>
    </div>
  </div>

  <div class="track-timeline" data-track-timeline data-count="<?= count($events) ?>">
    <?php foreach ($events as $index => $event): ?>
      <div class="track-step <?= $index === count($events) - 1 ? 'current' : 'done' ?>">
        <h4><?= e(order_status_label((string) $event['status'])) ?></h4>
        <p><?= e($event['message']) ?></p>
        <time datetime="<?= e((string) $event['created_at']) ?>"><?= e(fmt_datetime((string) $event['created_at'])) ?></time>
      </div>
    <?php endforeach; ?>
    <?php if ($events === []): ?>
      <div class="track-step current"><h4>Order placed</h4><p>We are confirming your order.</p><time><?= e(fmt_datetime((string) $order['placed_at'])) ?></time></div>
    <?php endif; ?>
  </div>

  <?php if (Settings::get('tracking_note') !== ''): ?>
    <p class="small text-ink-500 mb-0 mt-2"><?= e(Settings::get('tracking_note')) ?></p>
  <?php endif; ?>
</div>

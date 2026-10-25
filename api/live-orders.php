<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Auth::requirePermission('liveboard.view');
RateLimiter::guard('api');

$orders = Orders::liveBoard(25);
$counts = Orders::countsByStatus();

ob_start();
foreach ($orders as $order) {
    $status = (string) $order['status'];
    $color = order_status_color($status);
    ?>
    <tr>
      <td>
        <a class="fw-semibold text-decoration-none small" href="/admin/order.php?id=<?= (int) $order['id'] ?>"><?= e($order['order_number']) ?></a>
        <div class="text-ink-400" style="font-size:.7rem"><?= e(time_ago((string) $order['placed_at'])) ?></div>
      </td>
      <td class="small">
        <div><?= e($order['customer_name']) ?></div>
        <div class="text-ink-400" style="font-size:.7rem"><?= e($order['phone']) ?></div>
      </td>
      <td><span class="badge text-bg-<?= e($color) ?>"><?= e(order_status_label($status)) ?></span></td>
      <td class="small"><?= e(payment_method_label((string) $order['payment_method'])) ?></td>
      <td class="text-end small fw-semibold"><?= e(money($order['total'])) ?></td>
    </tr>
    <?php
}
$html = (string) ob_get_clean();

json_response([
    'count' => count($orders),
    'pending' => (int) ($counts['PENDING'] ?? 0),
    'in_transit' => (int) ($counts['SHIPPED'] ?? 0) + (int) ($counts['OUT_FOR_DELIVERY'] ?? 0),
    'html' => $html,
    'generated_at' => date('c'),
]);

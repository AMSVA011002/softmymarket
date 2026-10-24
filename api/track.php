<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$orderId = (int) ($_GET['order'] ?? 0);
$token = (string) ($_GET['token'] ?? '');
$order = $orderId > 0 ? Orders::find($orderId) : null;
if ($order === null) {
    json_response(['error' => 'Order not found.'], 404);
}

$payload = $token !== '' ? Security::verify($token) : null;
$authorised = ($payload !== null && (int) ($payload['order'] ?? 0) === (int) $order['id'])
    || (Auth::check() && Orders::ownedBy($order, Auth::id()))
    || Auth::isTeam();
if (!$authorised) {
    json_response(['error' => 'Not authorised.'], 403);
}

RateLimiter::guard('tracking');
$snapshot = OrderService::snapshot((int) $order['id']);
if ($snapshot === null) {
    json_response(['error' => 'Order not found.'], 404);
}
json_response($snapshot);

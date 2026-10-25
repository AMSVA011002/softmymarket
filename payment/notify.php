<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

/**
 * Server-to-server notification (webhook).
 *
 * Providers retry this endpoint, so it must be idempotent and must never trust
 * the payload before the signature check.
 */
header('Content-Type: text/plain; charset=utf-8');

$gatewayKey = (string) ($_GET['gateway'] ?? $_POST['gateway'] ?? '');
$gateway = $gatewayKey !== '' ? PaymentManager::get($gatewayKey) : null;
if ($gateway === null) {
    http_response_code(404);
    echo 'UNKNOWN_GATEWAY';
    exit;
}

$raw = (string) file_get_contents('php://input');
$payload = array_merge($_GET, $_POST);
if ($payload === [] && $raw !== '') {
    parse_str($raw, $parsed);
    $payload = array_merge($payload, (array) $parsed);
}

$orderNumber = (string) ($payload['order_id'] ?? $payload['order'] ?? $payload['merchant_order_id'] ?? '');
$order = $orderNumber !== '' ? Orders::findByNumber($orderNumber) : null;
if ($order === null) {
    $reference = (string) ($payload['reference'] ?? '');
    $payment = $reference !== '' ? Payments::findByReference($reference, $gateway->key) : null;
    $order = $payment !== null ? Orders::find((int) $payment['order_id']) : null;
}
if ($order === null) {
    http_response_code(404);
    echo 'UNKNOWN_ORDER';
    exit;
}

$result = $gateway->handleCallback($payload);
$payment = Payments::findByReference($result['reference'] ?? '', $gateway->key) ?? Orders::latestPayment((int) $order['id']);

Audit::log('payment.notify', 'order', (int) $order['id'], ['gateway' => $gateway->key, 'status' => $result['status']]);

if ($result['status'] === 'paid') {
    if ($payment !== null && strtolower((string) $payment['status']) !== 'paid') {
        Payments::markPaid((int) $payment['id'], $result['reference'], ['source' => 'notify', 'payload' => $payload]);
    }
    echo 'OK';
    exit;
}

if ($result['status'] === 'failed' && $payment !== null && strtolower((string) $payment['status']) !== 'paid') {
    Payments::markFailed((int) $payment['id'], ['source' => 'notify', 'payload' => $payload]);
    echo 'OK';
    exit;
}

echo 'IGNORED';

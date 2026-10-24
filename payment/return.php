<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

/**
 * Browser return URL from a payment provider (and from the sandbox screen).
 *
 * The provider's word is not trusted until its signature is verified, except
 * for the sandbox flow which carries our own signed token.
 */
$simToken = (string) ($_POST['sim_token'] ?? $_GET['sim_token'] ?? '');
$sim = $simToken !== '' ? Security::verify($simToken) : null;

if ($sim !== null) {
    $order = Orders::find((int) ($sim['sim_order'] ?? 0));
    $result = [
        'status'    => ((string) ($_POST['status'] ?? 'paid') === 'failed') ? 'failed' : 'paid',
        'reference' => (string) ($sim['ref'] ?? ''),
        'message'   => 'Sandbox payment completed.',
    ];
} else {
    $gatewayKey = (string) ($_GET['gateway'] ?? $_POST['gateway'] ?? '');
    $orderNumber = (string) ($_GET['order_id'] ?? $_POST['order_id'] ?? $_GET['order'] ?? $_POST['order'] ?? '');
    $order = $orderNumber !== '' ? Orders::findByNumber($orderNumber) : null;
    $gateway = $gatewayKey !== '' ? PaymentManager::get($gatewayKey) : null;
    if ($order === null || $gateway === null) {
        abort(404, 'We could not match that payment to an order.');
    }
    $result = $gateway->handleCallback(array_merge($_GET, $_POST));
}

if ($order === null) {
    abort(404, 'Order not found.');
}

$token = Security::sign(['order' => (int) $order['id'], 'n' => $order['order_number']], 60 * 60 * 24 * 30);
$back = '/order-success.php?order=' . rawurlencode((string) $order['uuid']) . '&token=' . rawurlencode($token);
$payment = Orders::latestPayment((int) $order['id']);

if ($result['status'] === 'paid') {
    if ($payment === null) {
        $paymentId = Payments::create((int) $order['id'], PaymentManager::keyForMethod((string) $order['payment_method']), (float) $order['total'], (string) $order['currency'], 'pending', $result['reference']);
        $payment = Payments::find($paymentId);
    }
    if ($payment !== null && strtolower((string) $payment['status']) !== 'paid') {
        Payments::markPaid((int) $payment['id'], $result['reference'] !== '' ? $result['reference'] : null, ['source' => 'return']);
    }
    Audit::log('payment.return_paid', 'order', (int) $order['id'], ['method' => $order['payment_method']]);
    flash('success', 'Payment received — thank you! Your order is confirmed.');
    redirect($back);
}

if ($result['status'] === 'failed') {
    if ($payment !== null && strtolower((string) $payment['status']) !== 'paid') {
        Payments::markFailed((int) $payment['id'], ['source' => 'return', 'reference' => $result['reference']]);
        Database::run('UPDATE orders SET payment_status = :ps WHERE id = :id AND payment_status <> :paid', [
            'ps' => 'FAILED', 'id' => (int) $order['id'], 'paid' => 'PAID',
        ]);
    }
    Orders::addEvent((int) $order['id'], (string) $order['status'], 'Payment attempt failed — the customer can try again.', false, 'system');
    flash('danger', 'The payment was not completed. You can try again below.');
    redirect($back);
}

flash('info', 'Your payment is still processing. We will confirm it as soon as the provider responds.');
redirect($back);

<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

RateLimiter::guard('payment');

$uuid = (string) query('order', '');
$token = (string) query('token', '');
$order = $uuid !== '' ? Orders::findByUuid($uuid) : null;
if ($order === null) {
    abort(404, 'Order not found.');
}

$payload = $token !== '' ? Security::verify($token) : null;
$authorised = (Auth::check() && Orders::ownedBy($order, Auth::id()))
    || Auth::isTeam()
    || ($payload !== null && (int) ($payload['order'] ?? 0) === (int) $order['id']);
if (!$authorised) {
    abort(403, 'This payment link has expired. Please sign in and try again.');
}

$back = '/order-success.php?order=' . rawurlencode((string) $order['uuid']) . '&token=' . rawurlencode($token);

if ($order['payment_status'] === 'PAID') {
    flash('info', 'This order is already paid.');
    redirect($back);
}

$gateway = PaymentManager::get((string) $order['payment_method']);
if ($gateway === null || !$gateway->enabled()) {
    flash('danger', 'That payment method is not available. Please contact support.');
    redirect($back);
}

if ($gateway instanceof BankTransferGateway) {
    flash('info', 'Transfer the amount to our bank account and upload the slip on this page.');
    redirect($back);
}

$payment = Payments::pendingForOrder((int) $order['id']);
if ($payment === null) {
    $paymentId = Payments::create(
        (int) $order['id'],
        PaymentManager::keyForMethod((string) $order['payment_method']),
        (float) $order['total'],
        (string) $order['currency'],
        'pending',
        null,
        ['method' => (string) $order['payment_method']]
    );
    $payment = Payments::find($paymentId);
}
if ($payment === null) {
    flash('danger', 'We could not start the payment. Please try again.');
    redirect($back);
}

try {
    $intent = $gateway->initiate($order, $payment);
} catch (Throwable $e) {
    error_log('Payment initiate failed: ' . $e->getMessage());
    flash('danger', 'The payment provider is not reachable right now. Please try again in a moment.');
    redirect($back);
}

if (($intent['type'] ?? '') === 'instructions') {
    flash('info', (string) ($intent['message'] ?? 'Follow the instructions on the order page.'));
    redirect($back);
}

if (($intent['type'] ?? '') === 'done') {
    flash('success', (string) ($intent['message'] ?? 'Payment step complete.'));
    redirect($back);
}

$method = strtoupper((string) ($intent['method'] ?? 'GET'));
$url = (string) ($intent['url'] ?? '');
if ($url === '') {
    flash('danger', 'The payment provider did not return a destination.');
    redirect($back);
}

Payments::update((int) $payment['id'], ['status' => 'initiated']);

if ($method === 'GET') {
    redirect($url);
}

$params = (array) ($intent['params'] ?? []);
$pageTitle = 'Redirecting to ' . payment_method_label((string) $order['payment_method']);
$noindex = true;
require INCLUDES_PATH . '/layout/header.php';
?>
<div class="auth-wrap">
  <div class="auth-card text-center">
    <div class="spinner-border text-brand mb-3" role="status" aria-hidden="true"></div>
    <h1 class="h6 fw-bold">Taking you to <?= e(payment_method_label((string) $order['payment_method'])) ?></h1>
    <p class="small text-ink-500">Do not close this window — your order <strong><?= e($order['order_number']) ?></strong> is reserved.</p>
    <form method="post" action="<?= e($url) ?>" id="gatewayForm">
      <?php foreach ($params as $key => $value): ?>
        <input type="hidden" name="<?= e((string) $key) ?>" value="<?= e((string) $value) ?>">
      <?php endforeach; ?>
      <noscript><button class="btn btn-brand" type="submit">Continue to payment</button></noscript>
    </form>
    <p class="small text-ink-400 mt-3 mb-0">Amount <?= e(money($order['total'])) ?></p>
  </div>
</div>
<script nonce="<?= e(Security::nonce()) ?>">document.getElementById('gatewayForm').submit();</script>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

/**
 * Sandbox checkout screen.
 *
 * Shown only while a hosted gateway has no live credentials (or when
 * simulate = true in config). It lets the full order → payment → confirmed
 * journey be demonstrated without charging a real card.
 */
$uuid = (string) query('order', '');
$token = (string) query('token', '');
$order = $uuid !== '' ? Orders::findByUuid($uuid) : null;
$payload = $token !== '' ? Security::verify($token) : null;
$authorised = $order !== null && (
    (Auth::check() && Orders::ownedBy($order, Auth::id()))
    || Auth::isTeam()
    || ($payload !== null && (int) ($payload['order'] ?? 0) === (int) $order['id'])
);
if ($order === null || !$authorised) {
    abort(404, 'Payment session not found.');
}

$gateway = PaymentManager::get((string) $order['payment_method']);
if (!$gateway instanceof HostedGateway) {
    abort(404, 'Simulation is only available for hosted gateways.');
}
$config = (array) Config::get('payments.' . $gateway->key, []);
$simulating = !empty($config['simulate']) || empty($config['merchant_id']) || empty($config['secret']);
if (!$simulating) {
    abort(404, 'Simulation is disabled for this gateway.');
}

$back = '/order-success.php?order=' . rawurlencode((string) $order['uuid']) . '&token=' . rawurlencode($token);
$payment = Orders::latestPayment((int) $order['id']);
$reference = $payment['gateway_reference'] ?? ('SIM-' . strtoupper(bin2hex(random_bytes(4))));
$outcome = Security::sign(['sim_order' => (int) $order['id'], 'sim_result' => 'paid', 'ref' => $reference, 'gw' => $gateway->key], 1800);

$pageTitle = 'Sandbox payment';
$noindex = true;
require INCLUDES_PATH . '/layout/header.php';
?>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <span class="logo"><span class="logo-mark"><?= e($gateway->label()[0] ?? 'P') ?></span></span>
      <span class="badge text-bg-warning">Sandbox mode</span>
    </div>
    <h1 class="h6 fw-bold mb-1"><?= e(payment_method_label((string) $order['payment_method'])) ?></h1>
    <p class="small text-ink-500">Merchant: <?= e(Settings::get('store_name')) ?> · Order <?= e($order['order_number']) ?></p>
    <div class="summary-row"><span>Amount</span><span class="fw-bold"><?= e(money($order['total'])) ?></span></div>
    <div class="summary-row"><span>Reference</span><span class="font-monospace small"><?= e($reference) ?></span></div>
    <div class="alert alert-warning small mt-3"><i class="bi bi-info-circle me-1"></i>This screen replaces the bank page until live credentials are configured. No money moves.</div>

    <form method="post" action="/payment/return.php" class="d-grid gap-2">
      <?= Csrf::field() ?>
      <input type="hidden" name="sim_token" value="<?= e($outcome) ?>">
      <input type="hidden" name="status" value="paid">
      <button class="btn btn-brand" type="submit"><i class="bi bi-lock me-1"></i> Pay <?= e(money($order['total'])) ?> successfully</button>
    </form>
    <form method="post" action="/payment/return.php" class="d-grid gap-2 mt-2">
      <?= Csrf::field() ?>
      <input type="hidden" name="sim_token" value="<?= e($outcome) ?>">
      <input type="hidden" name="status" value="failed">
      <button class="btn btn-soft" type="submit">Simulate a failed payment</button>
    </form>
    <a class="btn btn-link btn-sm w-100 mt-2 text-decoration-none" href="<?= e($back) ?>">Cancel and return to the order</a>
  </div>
</div>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

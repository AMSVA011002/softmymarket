<?php
/**
 * Payment reconciliation: bank-transfer slips to verify and the ledger.
 */
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
admin_require('payments.verify');

$from = (string) query('from', date('Y-m-01'));
$to = (string) query('to', date('Y-m-d'));
$gateway = (string) query('gateway', '');
$status = strtolower((string) query('status', ''));

$search = trim((string) query('q', ''));
$where = ['pay.created_at BETWEEN :from AND :to'];
$params = ['from' => $from . ' 00:00:00', 'to' => $to . ' 23:59:59'];
if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(o.order_number LIKE :pq1 OR o.customer_name LIKE :pq2 OR pay.gateway_reference LIKE :pq3 OR pay.gateway LIKE :pq4)';
    $params['pq1'] = $like;
    $params['pq2'] = $like;
    $params['pq3'] = $like;
    $params['pq4'] = $like;
}
if ($gateway !== '') {
    $where[] = 'pay.gateway = :gateway';
    $params['gateway'] = $gateway;
}
if (in_array($status, ['pending', 'paid', 'failed', 'refunded', 'initiated', 'submitted'], true)) {
    $where[] = 'pay.status = :status';
    $params['status'] = $status;
}
$whereSql = implode(' AND ', $where);
$total = Database::int("SELECT COUNT(*) FROM payments pay WHERE {$whereSql}", $params);
$pager = paginate($total, query_int('page', 1), 25);
$ledger = Database::all(
    "SELECT pay.*, o.order_number, o.customer_name, o.status AS order_status, o.total AS order_total
       FROM payments pay JOIN orders o ON o.id = pay.order_id
      WHERE {$whereSql}
   ORDER BY pay.created_at DESC
      LIMIT {$pager['limit']} OFFSET {$pager['offset']}",
    $params
);
$pending = Payments::pendingVerification(10);
$summary = Payments::summary($from, $to);
$collected = Payments::collectedTotal($from, $to);
$returnUrl = admin_url('payments.php', ['from' => $from, 'to' => $to, 'gateway' => $gateway, 'status' => $status]);

$adminTitle = 'Payments';
$adminSubtitle = money($collected) . ' collected between ' . fmt_date($from) . ' and ' . fmt_date($to);
require __DIR__ . '/includes/layout-top.php';
?>
<div class="row g-2 g-md-3 mb-3">
  <?php
  $tiles = [
      ['Collected', money($collected), 'bi-bank'],
      ['Awaiting verification', (string) count($pending), 'bi-hourglass-split'],
      ['Payment records', number_format($total), 'bi-receipt'],
      ['Methods in use', (string) count($summary), 'bi-credit-card'],
  ];
  foreach ($tiles as [$label, $value, $icon]): ?>
    <div class="col-6 col-xl-3">
      <div class="kpi-card h-100">
        <div class="d-flex justify-content-between align-items-start">
          <div class="min-w-0"><div class="kpi-label"><?= e($label) ?></div><div class="kpi-value text-truncate"><?= e($value) ?></div></div>
          <span class="kpi-icon"><i class="bi <?= e($icon) ?>"></i></span>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($pending !== []): ?>
  <div class="card-soft p-3 mb-3">
    <h2 class="h6 fw-bold mb-2"><i class="bi bi-bank me-1"></i> Bank transfers to verify</h2>
    <div class="row g-2">
      <?php foreach ($pending as $payment): ?>
        <div class="col-md-6">
          <div class="p-2 rounded" style="border:1px solid var(--ink-200)">
            <div class="d-flex justify-content-between gap-2">
              <a class="fw-semibold small text-decoration-none" href="<?= e(admin_url('order.php', ['id' => (int) $payment['order_id']])) ?>"><?= e((string) $payment['order_number']) ?></a>
              <span class="small fw-bold"><?= e(money((float) $payment['amount'])) ?></span>
            </div>
            <div class="small text-ink-500"><?= e((string) $payment['customer_name']) ?> · <?= e(time_ago((string) $payment['created_at'])) ?></div>
            <?php if (!empty($payment['receipt_url'])): ?>
              <a class="small" href="<?= e((string) $payment['receipt_url']) ?>" target="_blank" rel="noopener"><i class="bi bi-paperclip"></i> View slip</a>
            <?php else: ?>
              <span class="small text-ink-400">No slip uploaded yet</span>
            <?php endif; ?>
            <form method="post" action="/admin/actions.php" class="mt-2 d-flex gap-1" data-confirm="Confirm this transfer was received?">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="payment_verify">
              <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
              <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
              <button class="btn btn-sm btn-brand" type="submit"><i class="bi bi-check2 me-1"></i> Verify &amp; mark paid</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<form class="card-soft p-3 mb-3" method="get" action="<?= e(admin_url('payments.php')) ?>" data-live-filter>
  <div class="row g-2 align-items-end">
    <div class="col-12">
      <label class="form-label small mb-1" for="pay-q">Search</label>
      <input class="form-control form-control-sm" id="pay-q" name="q" type="search" value="<?= e($search) ?>"
             placeholder="Order number, customer, gateway reference…" data-live-input autocomplete="off">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small mb-1" for="pay-from">From</label>
      <input class="form-control form-control-sm" id="pay-from" type="date" name="from" value="<?= e($from) ?>">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small mb-1" for="pay-to">To</label>
      <input class="form-control form-control-sm" id="pay-to" type="date" name="to" value="<?= e($to) ?>">
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small mb-1" for="pay-gateway">Gateway</label>
      <select class="form-select form-select-sm" id="pay-gateway" name="gateway">
        <option value="">All gateways</option>
        <?php foreach (PaymentManager::gateways() as $code => $gatewayObject): ?>
          <option value="<?= e($gatewayObject->key) ?>" <?= $gateway === $gatewayObject->key ? 'selected' : '' ?>><?= e(payment_method_label($code)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small mb-1" for="pay-status">Status</label>
      <select class="form-select form-select-sm" id="pay-status" name="status">
        <option value="">Any</option>
        <?php foreach (['pending' => 'Pending', 'submitted' => 'Slip submitted', 'initiated' => 'Redirected', 'paid' => 'Paid', 'failed' => 'Failed', 'refunded' => 'Refunded'] as $value => $label): ?>
          <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12 col-md-2 d-grid"><button class="btn btn-sm btn-brand" type="submit">Apply</button></div>
  </div>
</form>

<div data-live-results>
<div class="card-soft p-0">
  <div class="table-responsive">
    <table class="table table-admin mb-0">
      <thead><tr><th>Order</th><th>Gateway</th><th>Reference</th><th class="text-end">Amount</th><th>Status</th><th>When</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($ledger as $payment): $state = strtolower((string) $payment['status']); ?>
          <tr>
            <td>
              <a class="text-decoration-none fw-semibold" href="<?= e(admin_url('order.php', ['id' => (int) $payment['order_id']])) ?>"><?= e((string) $payment['order_number']) ?></a>
              <div class="text-ink-400" style="font-size:.68rem"><?= e((string) $payment['customer_name']) ?></div>
            </td>
            <td class="small"><?= e(payment_method_label(PaymentManager::methodCode((string) $payment['gateway']))) ?></td>
            <td class="small text-ink-500"><?= e((string) ($payment['gateway_reference'] ?? '—')) ?></td>
            <td class="text-end small fw-semibold"><?= e(money((float) $payment['amount'])) ?></td>
            <td><span class="badge text-bg-<?= $state === 'paid' ? 'success' : ($state === 'failed' ? 'danger' : 'warning') ?>"><?= e(humanize($state)) ?></span></td>
            <td class="small text-ink-500 text-nowrap"><?= e(fmt_datetime((string) $payment['created_at'])) ?></td>
            <td class="text-end text-nowrap">
              <?php if (!empty($payment['receipt_url'])): ?>
                <a class="btn btn-sm btn-soft" href="<?= e((string) $payment['receipt_url']) ?>" target="_blank" rel="noopener" title="Slip"><i class="bi bi-paperclip"></i></a>
              <?php endif; ?>
              <?php if ($state !== 'paid'): ?>
                <form method="post" action="/admin/actions.php" class="d-inline">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="payment_mark_paid">
                  <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                  <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                  <button class="btn btn-sm btn-soft" type="submit" title="Mark paid"><i class="bi bi-check2"></i></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($ledger === []): ?><tr><td colspan="7" class="text-center small text-ink-500 py-4">No payments in this window.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="mt-3"><?= pagination_links($pager, ['from' => $from, 'to' => $to, 'gateway' => $gateway, 'status' => $status, 'q' => $search]) ?></div>
</div><!-- /data-live-results -->

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>

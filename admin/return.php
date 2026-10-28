<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
admin_require('orders.view');
$id = query_int('id');
$request = ReturnService::find($id);
if ($request === null) {
    flash('danger', 'Return request not found.');
    redirect(admin_url('returns.php'));
}
$items = ReturnService::items($id);
$events = ReturnService::events($id);
$allowed = ReturnService::TRANSITIONS[$request['status']] ?? [];
$itemValue = 0.0;
foreach ($items as $item) {
    $itemValue += (float) $item['unit_price'] * (int) $item['quantity'];
}
$priorRefunded = Database::float("SELECT COALESCE(SUM(refund_amount),0) FROM return_requests WHERE order_id = :oid AND status = 'REFUNDED' AND id <> :id", ['oid' => (int) $request['order_id'], 'id' => $id]);
$refundCap = ReturnService::calculateRefundCap($itemValue, (float) $request['order_subtotal'], (float) $request['order_discount'], (float) $request['order_tax'], (float) $request['order_total'], $priorRefunded);
$returnUrl = admin_url('return.php', ['id' => $id]);
$adminTitle = 'Return ' . $request['return_number'];
$adminSubtitle = 'Order ' . $request['order_number'] . ' · ' . $request['customer_name'] . ' · submitted ' . fmt_datetime((string) $request['created_at']);
$adminActions = '<a class="btn btn-sm btn-soft" href="' . e(admin_url('returns.php')) . '"><i class="bi bi-arrow-left me-1"></i> All returns</a>'
    . '<a class="btn btn-sm btn-soft" href="' . e(admin_url('order.php', ['id' => (int) $request['order_id']])) . '">Open order</a>';
require __DIR__ . '/includes/layout-top.php';
?>
<div class="row g-3"><div class="col-lg-8">
  <div class="card-soft p-3 p-md-4 mb-3"><div class="d-flex flex-wrap justify-content-between gap-2"><div><h2 class="h6 fw-bold mb-1">Request details</h2><span class="badge text-bg-<?= in_array($request['status'], ['REJECTED','CANCELLED'], true) ? 'secondary' : (in_array($request['status'], ['REFUNDED','EXCHANGED','CLOSED'], true) ? 'success' : 'warning') ?>"><?= e(humanize($request['status'])) ?></span></div><div class="text-end small"><div><?= e(ReturnService::REASONS[$request['reason']] ?? humanize($request['reason'])) ?></div><div class="text-ink-500">Refund cap: <?= e(money($refundCap)) ?></div></div></div>
    <p class="small mt-3 mb-2"><?= nl2br(e((string) $request['customer_details'])) ?></p>
    <?php if (!empty($request['evidence_file'])): ?><a class="btn btn-sm btn-soft" href="/account/return-evidence.php?id=<?= $id ?>"><i class="bi bi-paperclip me-1"></i> Download customer evidence</a><?php endif; ?>
    <div class="table-responsive mt-3"><table class="table table-admin align-middle mb-0"><thead><tr><th>Item</th><th>SKU</th><th>Qty</th><th class="text-end">Unit value</th><th class="text-end">Line value</th></tr></thead><tbody><?php foreach ($items as $item): ?><tr><td><?= e($item['item_title']) ?></td><td><?= e((string) ($item['sku'] ?? '—')) ?></td><td><?= (int) $item['quantity'] ?></td><td class="text-end"><?= e(money($item['unit_price'])) ?></td><td class="text-end"><?= e(money((float) $item['unit_price'] * (int) $item['quantity'])) ?></td></tr><?php endforeach; ?></tbody><tfoot><tr><th colspan="4" class="text-end">Maximum refund value</th><th class="text-end"><?= e(money($itemValue)) ?></th></tr></tfoot></table></div>
    <?php if ($request['return_tracking']): ?><p class="small mt-3 mb-0"><strong>Return shipment:</strong> <?= e((string) $request['return_carrier']) ?> · <?= e((string) $request['return_tracking']) ?></p><?php endif; ?>
  </div>
  <div class="card-soft p-3 p-md-4"><h2 class="h6 fw-bold">Request timeline</h2><ul class="list-unstyled mb-0 small"><?php foreach (array_reverse($events) as $event): ?><li class="d-flex gap-2 py-2 border-bottom"><i class="bi bi-circle-fill text-brand mt-1" style="font-size:.5rem"></i><div><strong><?= e(humanize($event['status'])) ?></strong><div><?= e($event['message']) ?></div><time class="text-ink-400"><?= e(fmt_datetime((string) $event['created_at'])) ?> · <?= e(humanize($event['actor_type'])) ?></time></div></li><?php endforeach; ?></ul></div>
</div><div class="col-lg-4">
  <?php if (Auth::can('orders.manage') && $allowed !== []): ?><div class="card-soft p-3 mb-3"><h2 class="h6 fw-bold">Update status</h2><form method="post" action="/admin/actions.php" data-disable-on-submit><?= Csrf::field() ?><input type="hidden" name="action" value="return_status"><input type="hidden" name="return_id" value="<?= $id ?>"><input type="hidden" name="return" value="<?= e($returnUrl) ?>"><label class="form-label small" for="return-next">Next step</label><select class="form-select form-select-sm mb-2" id="return-next" name="status" required><?php foreach ($allowed as $next): ?><option value="<?= e($next) ?>"><?= e(humanize($next)) ?></option><?php endforeach; ?></select><label class="form-label small" for="return-message">Update for customer (optional)</label><textarea class="form-control form-control-sm mb-2" id="return-message" name="message" rows="3" maxlength="500"></textarea><button class="btn btn-brand btn-sm w-100" type="submit">Save update</button></form></div><?php endif; ?>
  <?php if (Auth::can('payments.verify') && $request['status'] === 'INSPECTING'): ?>
    <?php if ($refundCap > 0): ?>
    <div class="card-soft p-3 mb-3"><h2 class="h6 fw-bold">Record manual refund</h2><p class="small text-ink-500">This records the refund and does not send money. Confirm the transfer/provider refund separately. Cap includes pro-rated item discount/tax, excludes delivery charges, and is limited by any prior refunds. Maximum <?= e(money($refundCap)) ?>.</p><form method="post" action="/admin/actions.php" data-confirm="Record this refund? This does not itself transfer money."><?= Csrf::field() ?><input type="hidden" name="action" value="return_refund"><input type="hidden" name="return_id" value="<?= $id ?>"><input type="hidden" name="return" value="<?= e($returnUrl) ?>"><label class="form-label small">Amount</label><input class="form-control form-control-sm mb-2" type="number" name="amount" min="0.01" max="<?= e(number_format($refundCap, 2, '.', '')) ?>" step="0.01" required><label class="form-label small">Refund method</label><input class="form-control form-control-sm mb-2" name="method" maxlength="40" placeholder="Bank transfer, card provider…" required><label class="form-label small">Reference (optional)</label><input class="form-control form-control-sm mb-2" name="reference" maxlength="120"><label class="form-label small">Customer update (optional)</label><textarea class="form-control form-control-sm mb-2" name="message" rows="2" maxlength="400"></textarea><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="restock" id="refund-restock" value="1"><label class="form-check-label small" for="refund-restock">Restock returned items as sellable inventory</label></div><button class="btn btn-outline-danger btn-sm w-100" type="submit">Record refund</button></form></div>
    <?php else: ?><div class="card-soft p-3 mb-3"><strong class="small">No refundable balance remains</strong><p class="small text-ink-500 mb-0">The value available for refund on this order is fully used or the returned item value is zero. An exchange may still be recorded.</p></div><?php endif; ?>
    <div class="card-soft p-3 mb-3"><h2 class="h6 fw-bold">Record exchange</h2><p class="small text-ink-500">Record the outcome here; replacement order/dispatch is handled separately.</p><form method="post" action="/admin/actions.php" data-confirm="Record this return as exchanged?"><?= Csrf::field() ?><input type="hidden" name="action" value="return_exchange"><input type="hidden" name="return_id" value="<?= $id ?>"><input type="hidden" name="return" value="<?= e($returnUrl) ?>"><label class="form-label small">Customer update</label><textarea class="form-control form-control-sm mb-2" name="message" rows="2" maxlength="400"></textarea><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="restock" id="exchange-restock" value="1"><label class="form-check-label small" for="exchange-restock">Restock returned items as sellable inventory</label></div><button class="btn btn-soft btn-sm w-100" type="submit">Record exchange</button></form></div>
  <?php endif; ?>
  <div class="card-soft p-3"><h2 class="h6 fw-bold">Customer</h2><div class="small fw-semibold"><?= e($request['customer_name']) ?></div><a class="small" href="mailto:<?= e($request['email']) ?>"><?= e($request['email']) ?></a><hr><h3 class="small fw-bold">Recorded outcome</h3><?php if ($request['status'] === 'REFUNDED'): ?><p class="small mb-0"><?= e(money($request['refund_amount'])) ?> via <?= e((string) $request['refund_method']) ?><?= $request['refund_reference'] ? ' · ' . e($request['refund_reference']) : '' ?></p><?php elseif ($request['status'] === 'EXCHANGED'): ?><p class="small mb-0">Exchange recorded<?= $request['restocked_at'] ? '; returned units restocked' : '' ?>.</p><?php else: ?><p class="small text-ink-500 mb-0">No final outcome recorded.</p><?php endif; ?></div>
</div></div>
<?php require __DIR__ . '/includes/layout-bottom.php'; ?>

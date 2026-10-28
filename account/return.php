<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
Auth::requireLogin();
$id = query_int('id');
$request = $id > 0 ? ReturnService::find($id) : null;
if ($request === null || !ReturnService::ownedBy($request, (int) Auth::id())) {
    abort(404, 'Return request not found.');
}
$items = ReturnService::items($id);
$events = ReturnService::events($id);
$canCancel = in_array($request['status'], ['REQUESTED', 'APPROVED'], true);
$accountActive = 'returns';
$pageTitle = 'Return ' . $request['return_number'];
$noindex = true;
require INCLUDES_PATH . '/layout/header.php';
?>
<section class="section"><div class="container-page">
  <?= View::breadcrumbs(['My returns' => '/account/returns.php', $request['return_number'] => null]) ?>
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mt-2 mb-3">
    <div><h1 class="h5 fw-bold mb-1">Return <?= e($request['return_number']) ?></h1><p class="small text-ink-500 mb-0">Order <a href="/account/order.php?id=<?= (int) $request['order_id'] ?>"><?= e($request['order_number']) ?></a> · submitted <?= e(fmt_datetime((string) $request['created_at'])) ?></p></div>
    <span class="badge rounded-pill text-bg-<?= in_array($request['status'], ['REJECTED','CANCELLED'], true) ? 'secondary' : (in_array($request['status'], ['REFUNDED','EXCHANGED','CLOSED'], true) ? 'success' : 'warning') ?> fs-6"><?= e(humanize((string) $request['status'])) ?></span>
  </div>
  <div class="row g-4">
    <div class="col-lg-8">
      <div class="card-soft p-3 p-md-4 mb-3"><h2 class="h6 fw-bold">Items requested</h2><div class="table-responsive"><table class="table table-admin align-middle mb-0"><thead><tr><th>Item</th><th>SKU</th><th>Quantity</th><th class="text-end">Unit value</th></tr></thead><tbody>
        <?php foreach ($items as $item): ?><tr><td><?= e($item['item_title']) ?></td><td><?= e((string) ($item['sku'] ?? '—')) ?></td><td><?= (int) $item['quantity'] ?></td><td class="text-end"><?= e(money($item['unit_price'])) ?></td></tr><?php endforeach; ?>
      </tbody></table></div><p class="small mb-1"><strong>Reason:</strong> <?= e(ReturnService::REASONS[$request['reason']] ?? humanize($request['reason'])) ?></p><p class="small text-ink-600 mb-0"><strong>Your description:</strong><br><?= nl2br(e((string) $request['customer_details'])) ?></p>
        <?php if (!empty($request['evidence_file'])): ?><a class="btn btn-sm btn-soft mt-3" href="/account/return-evidence.php?id=<?= $id ?>"><i class="bi bi-paperclip me-1"></i> Download submitted evidence</a><?php endif; ?>
      </div>
      <?php if ($request['status'] === 'APPROVED'): ?><div class="card-soft p-3 p-md-4 mb-3"><h2 class="h6 fw-bold">Send the return parcel</h2><p class="small text-ink-600">Arrange the return shipment using the instructions from the store. Carrier booking and shipping costs are not handled by the website.</p>
        <form method="post" action="/actions/returns.php" class="row g-2"><?= Csrf::field() ?><input type="hidden" name="action" value="tracking"><input type="hidden" name="return_id" value="<?= $id ?>"><div class="col-md-5"><label class="form-label small">Carrier</label><input class="form-control" name="carrier" maxlength="80" required></div><div class="col-md-5"><label class="form-label small">Tracking number</label><input class="form-control" name="tracking" maxlength="120" required></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-brand w-100" type="submit">Save</button></div></form>
      </div><?php elseif ($request['return_tracking']): ?><div class="card-soft p-3 mb-3"><strong class="small">Return shipment:</strong> <?= e((string) $request['return_carrier']) ?> · <?= e((string) $request['return_tracking']) ?></div><?php endif; ?>
      <div class="card-soft p-3 p-md-4"><h2 class="h6 fw-bold mb-3">Return history</h2><ul class="list-unstyled mb-0 small"><?php foreach (array_reverse($events) as $event): ?><li class="d-flex gap-2 py-2 border-bottom"><i class="bi bi-circle-fill text-brand mt-1" style="font-size:.5rem"></i><div><strong><?= e(humanize((string) $event['status'])) ?></strong><div><?= e((string) $event['message']) ?></div><time class="text-ink-400"><?= e(fmt_datetime((string) $event['created_at'])) ?></time></div></li><?php endforeach; ?></ul></div>
    </div>
    <div class="col-lg-4"><div class="card-soft p-3 mb-3"><h2 class="h6 fw-bold">Outcome</h2><?php if ($request['status'] === 'REFUNDED'): ?><p class="small mb-1">Refund recorded: <strong><?= e(money($request['refund_amount'])) ?></strong></p><p class="small text-ink-500 mb-0">Method: <?= e((string) $request['refund_method']) ?>. Please allow time for your bank/payment provider to process it.</p><?php elseif ($request['status'] === 'EXCHANGED'): ?><p class="small mb-0">The store has recorded this return as exchanged. Contact support if you need replacement delivery details.</p><?php elseif ($request['status'] === 'REJECTED'): ?><p class="small mb-0">This request was declined. Contact support if you need clarification.</p><?php else: ?><p class="small text-ink-500 mb-0">The store will review your request and post updates here. Approval and refund/exchange are subject to the store's return policy and inspection.</p><?php endif; ?></div>
      <?php if ($canCancel): ?><div class="card-soft p-3"><form method="post" action="/actions/returns.php" data-confirm="Cancel this return request?"><?= Csrf::field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="return_id" value="<?= $id ?>"><button class="btn btn-outline-danger btn-sm w-100" type="submit">Cancel request</button></form></div><?php endif; ?>
    </div>
  </div>
</div></section>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

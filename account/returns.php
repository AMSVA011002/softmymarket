<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
Auth::requireLogin();
$userId = (int) Auth::id();
$requests = ReturnService::forUser($userId);
$eligibleOrders = ReturnService::eligibleOrders($userId);
$returnWindowDays = max(1, min(90, Settings::int('return_window_days', 7)));
$selectedId = query_int('order_id');
$selectedOrder = null;
foreach ($eligibleOrders as $candidate) {
    if ((int) $candidate['id'] === $selectedId) {
        $selectedOrder = $candidate;
        break;
    }
}
$eligibleItems = $selectedOrder !== null ? ReturnService::eligibleItems((int) $selectedOrder['id']) : [];
$accountActive = 'returns';
$pageTitle = 'Returns & exchanges';
$noindex = true;
require INCLUDES_PATH . '/layout/header.php';
?>
<section class="section">
  <div class="container-page">
    <h1 class="h4 fw-bold mb-3">Returns & exchanges</h1>
    <div class="row g-4">
      <div class="col-lg-3"><?php require INCLUDES_PATH . '/account-nav.php'; ?></div>
      <div class="col-lg-9">
        <div class="card-soft p-3 p-md-4 mb-4">
          <div class="d-flex justify-content-between align-items-start gap-3">
            <div><h2 class="h6 fw-bold mb-1">Request a return</h2><p class="small text-ink-500 mb-0">Submit within <?= (int) $returnWindowDays ?> days of delivery. Our team will review your request and update its status here.</p></div>
            <i class="bi bi-arrow-counterclockwise fs-3 text-brand"></i>
          </div>
          <?php if ($eligibleOrders === []): ?>
            <div class="alert alert-light small mt-3 mb-0">There are no delivered orders currently eligible for a return request. Return requests must be made within <?= (int) $returnWindowDays ?> days of delivery.</div>
          <?php else: ?>
            <form method="get" class="row g-2 align-items-end mt-2">
              <div class="col-md-9"><label class="form-label small" for="return-order">Choose a delivered order</label>
                <select class="form-select" name="order_id" id="return-order" required>
                  <option value="">Select order…</option>
                  <?php foreach ($eligibleOrders as $candidate): ?>
                    <option value="<?= (int) $candidate['id'] ?>" <?= $selectedId === (int) $candidate['id'] ? 'selected' : '' ?>><?= e($candidate['order_number']) ?> · delivered <?= e(fmt_date((string) ($candidate['delivered_on'] ?? $candidate['delivered_at']))) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-3"><button class="btn btn-soft w-100" type="submit">Select order</button></div>
            </form>
            <?php if ($selectedOrder !== null && $eligibleItems !== []): ?>
              <form method="post" action="/actions/returns.php" enctype="multipart/form-data" class="mt-3" data-disable-on-submit>
                <?= Csrf::field() ?><input type="hidden" name="action" value="create"><input type="hidden" name="order_id" value="<?= (int) $selectedOrder['id'] ?>">
                <div class="table-responsive"><table class="table table-admin align-middle">
                  <thead><tr><th>Item</th><th>Purchased</th><th>Still returnable</th><th style="width:130px">Return quantity</th></tr></thead>
                  <tbody><?php foreach ($eligibleItems as $item): ?>
                    <tr><td><div class="d-flex align-items-center gap-2"><?php if ($item['image_url']): ?><img src="<?= e(img_url((string) $item['image_url'])) ?>" alt="" width="42" height="42" class="rounded object-fit-cover"><?php endif; ?><span><?= e($item['title']) ?><small class="d-block text-ink-400"><?= e((string) ($item['sku'] ?? '')) ?></small></span></div></td>
                      <td><?= (int) $item['quantity'] ?></td><td><?= (int) $item['returnable_quantity'] ?></td>
                      <td><input class="form-control form-control-sm" type="number" name="quantity[<?= (int) $item['id'] ?>]" min="0" max="<?= (int) $item['returnable_quantity'] ?>" value="0" aria-label="Return quantity for <?= e($item['title']) ?>"></td></tr>
                  <?php endforeach; ?></tbody>
                </table></div>
                <div class="row g-3">
                  <div class="col-md-6"><label class="form-label small" for="return-reason">Reason</label><select class="form-select" id="return-reason" name="reason" required><option value="">Choose a reason…</option><?php foreach (ReturnService::REASONS as $key => $label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
                  <div class="col-md-6"><label class="form-label small" for="return-evidence">Photo or document (optional, max 5 MB)</label><div class="form-text">For damaged or wrong items, submit within 48 hours and attach a photo.</div><input class="form-control" id="return-evidence" type="file" name="evidence" accept="image/jpeg,image/png,image/webp,image/gif,application/pdf"></div>
                  <div class="col-12"><label class="form-label small" for="return-details">Tell us what happened (10–2,000 characters)</label><textarea class="form-control" id="return-details" name="details" rows="4" minlength="10" maxlength="2000" required></textarea></div>
                  <div class="col-12 d-flex justify-content-between align-items-center gap-2"><span class="small text-ink-500">Submitting a request does not guarantee approval. Return shipping may be at your expense unless the item is faulty or incorrect.</span><button class="btn btn-brand flex-shrink-0" type="submit"><i class="bi bi-send me-1"></i> Submit request</button></div>
                </div>
              </form>
            <?php elseif ($selectedOrder !== null): ?><p class="small text-ink-500 mt-3 mb-0">All quantities from this order have already been included in a return request.</p><?php endif; ?>
          <?php endif; ?>
        </div>

        <h2 class="h6 fw-bold mb-2">Your return requests</h2>
        <?php if ($requests === []): ?>
          <div class="card-soft p-4 text-center small text-ink-500">Your return requests will appear here.</div>
        <?php else: ?>
          <?php foreach ($requests as $request): ?>
            <div class="card-soft p-3 mb-2"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
              <div><a class="fw-bold text-decoration-none" href="/account/return.php?id=<?= (int) $request['id'] ?>"><?= e($request['return_number']) ?></a><div class="small text-ink-500">Order <?= e($request['order_number']) ?> · requested <?= e(fmt_datetime((string) $request['created_at'])) ?> · <?= (int) $request['line_count'] ?> item line(s)</div></div>
              <div class="text-end"><span class="badge rounded-pill text-bg-<?= in_array($request['status'], ['REJECTED','CANCELLED'], true) ? 'secondary' : (in_array($request['status'], ['REFUNDED','EXCHANGED','CLOSED'], true) ? 'success' : 'warning') ?>"><?= e(humanize((string) $request['status'])) ?></span><a class="d-block small mt-1" href="/account/return.php?id=<?= (int) $request['id'] ?>">View details</a></div>
            </div></div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

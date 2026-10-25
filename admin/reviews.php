<?php
/**
 * Review moderation with inline replies.
 */
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
admin_require('reviews.manage');

$status = (string) query('status', 'pending');
if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
    $status = 'pending';
}
$search = trim((string) query('q', ''));
$list = Catalog::adminReviews([
    'status' => $status === 'all' ? '' : $status,
    'q' => $search,
    'page' => query_int('page', 1),
]);
$rows = $list['rows'];
$returnUrl = admin_url('reviews.php', ['status' => $status, 'q' => $search]);

$adminTitle = 'Reviews';
$adminSubtitle = number_format($list['total']) . ' ' . ($status === 'all' ? '' : $status . ' ') . 'review' . ($list['total'] === 1 ? '' : 's');
require __DIR__ . '/includes/layout-top.php';
?>
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $value => $label): ?>
    <a class="btn btn-sm <?= $status === $value ? 'btn-brand' : 'btn-soft' ?>" href="<?= e(admin_url('reviews.php', ['status' => $value])) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
  <form class="ms-auto d-flex gap-1" method="get" action="<?= e(admin_url('reviews.php')) ?>" data-live-filter data-live-target="#reviews-results">
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <input class="form-control form-control-sm" name="q" type="search" value="<?= e($search) ?>"
           placeholder="Search reviews" data-live-input autocomplete="off">
    <button class="btn btn-sm btn-soft" type="submit">Search</button>
  </form>
</div>

<div id="reviews-results" data-live-results>
<?php foreach ($rows as $review): ?>
  <div class="card-soft p-3 mb-2">
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-1">
      <div>
        <span class="fw-semibold small"><?= e($review['author_name']) ?></span>
        <?= View::stars((float) $review['rating']) ?>
        <span class="badge text-bg-<?= $review['status'] === 'approved' ? 'success' : ($review['status'] === 'pending' ? 'warning' : 'secondary') ?> ms-1"><?= e(humanize((string) $review['status'])) ?></span>
      </div>
      <span class="small text-ink-400"><?= e(fmt_datetime((string) $review['created_at'])) ?></span>
    </div>
    <p class="small mb-1">
      <a class="text-decoration-none" href="<?= e('/product.php?slug=' . rawurlencode((string) $review['product_slug'])) ?>" target="_blank" rel="noopener">
        <?= e($review['product_title']) ?> <i class="bi bi-box-arrow-up-right"></i>
      </a>
    </p>
    <?php if (!empty($review['title'])): ?><p class="small fw-semibold mb-1"><?= e($review['title']) ?></p><?php endif; ?>
    <p class="small text-ink-600"><?= nl2br(e((string) $review['body'])) ?></p>

    <?php if (!empty($review['admin_reply'])): ?>
      <div class="p-2 rounded small" style="background:var(--ink-50)"><strong>Store reply:</strong> <?= e((string) $review['admin_reply']) ?></div>
    <?php endif; ?>

    <div class="d-flex flex-wrap gap-2 align-items-start mt-2">
      <?php if ($review['status'] !== 'approved'): ?>
        <form method="post" action="/admin/actions.php">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="review_moderate">
          <input type="hidden" name="review_id" value="<?= (int) $review['id'] ?>">
          <input type="hidden" name="decision" value="approve">
          <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
          <button class="btn btn-sm btn-brand" type="submit"><i class="bi bi-check2 me-1"></i> Approve</button>
        </form>
      <?php endif; ?>
      <?php if ($review['status'] !== 'rejected'): ?>
        <form method="post" action="/admin/actions.php">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="review_moderate">
          <input type="hidden" name="review_id" value="<?= (int) $review['id'] ?>">
          <input type="hidden" name="decision" value="reject">
          <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
          <button class="btn btn-sm btn-soft" type="submit"><i class="bi bi-x-lg me-1"></i> Reject</button>
        </form>
      <?php endif; ?>
      <form class="d-flex gap-1 flex-grow-1" method="post" action="/admin/actions.php">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="review_reply">
        <input type="hidden" name="review_id" value="<?= (int) $review['id'] ?>">
        <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
        <input class="form-control form-control-sm" name="reply" placeholder="Reply publicly as the store" value="<?= e((string) ($review['admin_reply'] ?? '')) ?>" maxlength="1000">
        <button class="btn btn-sm btn-ocean text-nowrap" type="submit">Reply</button>
      </form>
      <?php if (Auth::can('settings.manage')): ?>
        <form method="post" action="/admin/actions.php" data-confirm="Delete this review permanently?">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="review_delete">
          <input type="hidden" name="review_id" value="<?= (int) $review['id'] ?>">
          <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
          <button class="btn btn-sm btn-soft" type="submit" aria-label="Delete"><i class="bi bi-trash"></i></button>
        </form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
<?php if ($rows === []): ?>
  <div class="card-soft p-4 text-center small text-ink-500"><i class="bi bi-star fs-4 d-block mb-2"></i>No reviews in this queue.</div>
<?php endif; ?>
<div class="mt-3"><?= pagination_links($list['pager'], ['status' => $status, 'q' => $search]) ?></div>
</div><!-- /#reviews-results -->

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>

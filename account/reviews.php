<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
Auth::requireLogin();

$rows = Database::all(
    'SELECT r.*, p.title AS product_title, p.slug AS product_slug, p.image_url
       FROM reviews r JOIN products p ON p.id = r.product_id
      WHERE r.user_id = :uid ORDER BY r.created_at DESC LIMIT 100',
    ['uid' => Auth::id()]
);
$accountActive = 'reviews';

$pageTitle = 'My reviews';
$noindex = true;
require INCLUDES_PATH . '/layout/header.php';
?>
<section class="section">
  <div class="container-page">
    <h1 class="h4 fw-bold mb-3">My reviews</h1>
    <div class="row g-4">
      <div class="col-lg-3"><?php require INCLUDES_PATH . '/account-nav.php'; ?></div>
      <div class="col-lg-9">
        <?php if ($rows === []): ?>
          <?= View::emptyState('bi-star', 'You have not reviewed anything yet', 'Reviews help other shoppers choose with confidence.', 'Browse products', '/shop.php') ?>
        <?php else: ?>
          <div class="card-soft p-3 p-md-4">
            <?php foreach ($rows as $review): ?>
              <div class="d-flex gap-3 py-3 border-bottom">
                <img src="<?= e(img_url($review['image_url'], (string) $review['product_title'], 120)) ?>" alt="" width="56" height="56" style="object-fit:cover;border-radius:8px" loading="lazy">
                <div class="flex-grow-1">
                  <div class="d-flex flex-wrap justify-content-between gap-2">
                    <a class="small fw-semibold text-decoration-none" href="/product.php?slug=<?= e($review['product_slug']) ?>"><?= e($review['product_title']) ?></a>
                    <span class="badge text-bg-<?= $review['status'] === 'approved' ? 'success' : ($review['status'] === 'rejected' ? 'danger' : 'warning') ?>"><?= e(ucfirst((string) $review['status'])) ?></span>
                  </div>
                  <div class="d-flex align-items-center gap-2 mt-1"><?= View::stars((float) $review['rating']) ?><span class="text-ink-400" style="font-size:.7rem"><?= e(fmt_date((string) $review['created_at'])) ?></span></div>
                  <?php if (!empty($review['title'])): ?><p class="small fw-semibold mb-1 mt-1"><?= e($review['title']) ?></p><?php endif; ?>
                  <p class="small text-ink-600 mb-0"><?= e($review['body']) ?></p>
                  <?php if (!empty($review['admin_reply'])): ?>
                    <div class="bg-ink-50 rounded-3 p-2 mt-2 small"><strong>Store reply:</strong> <?= e($review['admin_reply']) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

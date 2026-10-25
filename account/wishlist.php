<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
Auth::requireLogin();

$products = Catalog::productsByIds(Cart::wishlistIds());
$accountActive = 'wishlist';

$pageTitle = 'My wishlist';
$noindex = true;
require INCLUDES_PATH . '/layout/header.php';
?>
<section class="section">
  <div class="container-page">
    <h1 class="h4 fw-bold mb-3">My wishlist</h1>
    <div class="row g-4">
      <div class="col-lg-3"><?php require INCLUDES_PATH . '/account-nav.php'; ?></div>
      <div class="col-lg-9">
        <?php if ($products === []): ?>
          <?= View::emptyState('bi-heart', 'Your wishlist is empty', 'Tap the heart on any product to save it for later.', 'Browse products', '/shop.php') ?>
        <?php else: ?>
          <div class="grid-products">
            <?php foreach ($products as $product): ?><?= View::card($product) ?><?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

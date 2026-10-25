<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$brands = Catalog::brands(true);
$pageTitle = 'Shop by brand';
$pageDescription = 'Browse every brand stocked at SoftMyMarket.';

require INCLUDES_PATH . '/layout/header.php';
?>
<div class="breadcrumb-wrap"><div class="container-page"><?= View::breadcrumbs(['Brands' => null]) ?></div></div>
<section class="section">
  <div class="container-page">
    <div class="section-head">
      <div>
        <p class="eyebrow">Trusted names</p>
        <h1 class="h3 fw-bold">Shop by brand</h1>
        <p>Genuine products with local warranty from <?= count($brands) ?> brands.</p>
      </div>
    </div>
    <div class="row g-2 g-md-3">
      <?php foreach ($brands as $brand): ?>
        <div class="col-6 col-md-4 col-lg-3">
          <a class="card-soft card-soft-hover d-flex flex-column align-items-center justify-content-center gap-2 p-4 text-decoration-none h-100" href="/shop.php?brand[]=<?= e($brand['slug']) ?>">
            <?php if (!empty($brand['logo_url'])): ?>
              <img src="<?= e(img_url($brand['logo_url'], $brand['name'], 160)) ?>" alt="<?= e($brand['name']) ?>" height="40" style="object-fit:contain;max-width:120px" loading="lazy">
            <?php else: ?>
              <span class="logo-mark" style="width:44px;height:44px;font-size:.85rem"><?= e(initials($brand['name'], 2)) ?></span>
            <?php endif; ?>
            <span class="fw-semibold small text-ink-800"><?= e($brand['name']) ?></span>
            <span class="badge text-bg-light"><?= (int) $brand['product_count'] ?> products</span>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

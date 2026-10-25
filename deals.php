<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$filters = Catalog::parseFilters($_GET);
$filters['on_sale'] = true;
$filters['per'] = 24;
if (!in_array($filters['sort'], ['discount', 'popular', 'price_asc', 'newest'], true)) {
    $filters['sort'] = 'discount';
}
$result = Catalog::paginateProducts($filters);
$facets = Catalog::productFacets($filters);
$categories = View::categoryTree();
$dealEndsAt = strtotime('tomorrow 00:00:00') ?: time() + 3600;

$pageTitle = 'Today\'s deals & flash offers';
$pageDescription = 'Limited-time prices across electronics, fashion, home and daily essentials. Save more with our flash deals.';

require INCLUDES_PATH . '/layout/header.php';
?>

<div class="breadcrumb-wrap"><div class="container-page"><?= View::breadcrumbs(['Deals' => null]) ?></div></div>

<section class="section pb-0">
  <div class="container-page">
    <div class="deal-banner mb-4">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
          <p class="text-uppercase mb-1" style="font-size:.68rem;letter-spacing:.16em;opacity:.8;font-weight:800">Today only</p>
          <h1 class="h3 fw-bold mb-1"><i class="bi bi-lightning-charge-fill text-warning"></i> Flash deals</h1>
          <p class="mb-0 small" style="opacity:.85">Prices reset at midnight. <?= number_format($result['total']) ?> products on sale.</p>
        </div>
        <div class="countdown" data-countdown="<?= e(date('c', $dealEndsAt)) ?>">
          <span><b data-cd-hours>00</b><small>hrs</small></span>
          <span><b data-cd-mins>00</b><small>min</small></span>
          <span><b data-cd-secs>00</b><small>sec</small></span>
        </div>
      </div>
    </div>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
      <p class="small text-ink-500 mb-0">Sort: <?= e(ucfirst(str_replace('_', ' ', $filters['sort']))) ?></p>
      <div class="d-flex gap-2 align-items-center">
        <button class="btn btn-outline-brand btn-sm d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#filterDrawer"><i class="bi bi-funnel"></i> Filters</button>
        <div style="min-width:200px"><?= View::sortSelect($filters['sort']) ?></div>
      </div>
    </div>

    <div class="row g-4">
      <aside class="col-lg-3 d-none d-lg-block"><div class="sticky-sidebar"><?php $baseAction = '/deals.php'; $hiddenParams = []; require INCLUDES_PATH . '/partials/shop-filters.php'; ?></div></aside>
      <div class="col-lg-9">
        <div id="shop-results" data-live-results>
        <?php if ($result['rows'] === []): ?>
          <?= View::emptyState('bi-percent', 'No deals match these filters', 'Try clearing the filters to see every offer.', 'See all products', '/shop.php') ?>
        <?php else: ?>
          <div class="grid-products">
            <?php foreach ($result['rows'] as $product): ?><?= View::card($product) ?><?php endforeach; ?>
          </div>
          <div class="mt-4"><?= pagination_links($result['pager'], ['sort' => $filters['sort'], 'brand' => $filters['brand'], 'min' => $filters['min'], 'max' => $filters['max']]) ?></div>
        <?php endif; ?>
        </div><!-- /#shop-results -->
      </div>
    </div>
  </div>
</section>

<div role="dialog" aria-modal="true" class="offcanvas offcanvas-start" tabindex="-1" id="filterDrawer" aria-labelledby="filterDrawerLabel" style="width:min(360px,90vw)">
  <div class="offcanvas-header border-bottom">
    <h2 class="offcanvas-title h6 fw-bold" id="filterDrawerLabel">Filters</h2>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body"><?php $baseAction = '/deals.php'; $hiddenParams = []; require INCLUDES_PATH . '/partials/shop-filters.php'; ?></div>
</div>

<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

<?php
/**
 * Category landing page: sub-category chips, filters and a product grid for the
 * whole subtree (parent categories include every descendant's products).
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$slug = (string) query('slug', '');
$category = Catalog::categoryBySlug($slug);
if ($category === null || (int) $category['is_active'] === 0) {
    http_response_code(404);
    $pageTitle = 'Category not found';
    require INCLUDES_PATH . '/layout/header.php';
    echo '<section class="section"><div class="container-page">' . View::emptyState('bi-diagram-3', 'That category has moved', 'It may have been renamed or merged. Browse the full catalogue instead.', 'Shop all products', '/shop.php') . '</div></section>';
    require INCLUDES_PATH . '/layout/footer.php';
    exit;
}

$filters = Catalog::parseFilters($_GET);
$filters['category'] = $slug;
$filters['per'] = 24;
$results = Catalog::paginateProducts($filters);
$facets = Catalog::productFacets($filters);
$products = $results['rows'];
$total = (int) $results['total'];

$children = [];
foreach (Catalog::categories(true) as $candidate) {
    if ((int) ($candidate['parent_id'] ?? 0) === (int) $category['id']) {
        $counts = Catalog::categoryCounts();
        $candidate['product_count'] = $counts[(int) $candidate['id']] ?? 0;
        $children[] = $candidate;
    }
}
$counts = Catalog::categoryCounts();
$categoryCount = $counts[(int) $category['id']] ?? 0;
$trail = Catalog::categoryBreadcrumb($category);
$crumbs = [];
foreach ($trail as $node) {
    $crumbs[$node['name']] = '/category.php?slug=' . rawurlencode((string) $node['slug']);
}
$crumbs[$category['name']] = null;

$pageTitle = $category['meta_title'] ?: $category['name'];
$pageDescription = $category['meta_description'] ?: ($category['description'] ?: 'Shop ' . $category['name'] . ' online in Sri Lanka with fast delivery and easy returns.');
$noindex = $filters['page'] > 1;
require INCLUDES_PATH . '/layout/header.php';
?>
<div class="breadcrumb-wrap"><div class="container-page"><?= View::breadcrumbs($crumbs) ?></div></div>

<section class="section pb-0">
  <div class="container-page">
    <div class="card-soft p-3 p-md-4 mb-3" style="background:linear-gradient(120deg,#ecfdf0,#fff 60%)">
      <div class="d-flex flex-wrap align-items-center gap-3">
        <?= View::categoryIcon($category) ?>
        <div class="flex-grow-1 min-w-0">
          <h1 class="h4 fw-bold mb-1"><?= e($category['name']) ?></h1>
          <p class="small text-ink-600 mb-0">
            <?= number_format($total) ?> product<?= $total === 1 ? '' : 's' ?>
            <?= $children !== [] ? ' · ' . count($children) . ' sub-categor' . (count($children) === 1 ? 'y' : 'ies') : '' ?>
            <?php if ($category['description']): ?> · <?= e(limit_text((string) $category['description'], 120)) ?><?php endif; ?>
          </p>
        </div>
        <a class="btn btn-sm btn-soft" href="/shop.php">All products</a>
      </div>
    </div>

    <?php if ($children !== []): ?>
      <div class="d-flex gap-2 overflow-auto pb-2 mb-3" style="scrollbar-width:none">
        <?php foreach ($children as $child): ?>
          <a class="filter-chip text-nowrap" style="background:#fff;border:1px solid var(--ink-200);padding:.45rem .8rem" href="/category.php?slug=<?= e($child['slug']) ?>">
            <?= e($child['name']) ?> <span class="text-ink-400"><?= (int) $child['product_count'] ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
      <p class="small text-ink-500 mb-0">Showing <?= (int) $results['pager']['from'] ?>–<?= (int) $results['pager']['to'] ?> of <?= number_format($total) ?></p>
      <div class="d-flex gap-2">
        <button class="btn btn-sm btn-soft d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#filterDrawer"><i class="bi bi-funnel me-1"></i> Filters</button>
        <?= View::sortSelect((string) $filters['sort']) ?>
      </div>
    </div>

    <div class="row g-3 g-lg-4">
      <aside class="col-lg-3 d-none d-lg-block">
        <?php $baseAction = '/category.php'; $hiddenParams = ['slug' => $slug]; require INCLUDES_PATH . '/partials/shop-filters.php'; ?>
      </aside>
      <div class="col-lg-9">
        <div id="shop-results" data-live-results>
        <?php if ($products === []): ?>
          <?= View::emptyState('bi-box-seam', 'Nothing here yet', 'This category has no products matching your filters. Try another department or clear the filters.', 'Clear filters', '/category.php?slug=' . rawurlencode($slug)) ?>
        <?php else: ?>
          <div class="grid-products">
            <?php foreach ($products as $index => $product): ?><?= View::card($product, ['showQuickAdd' => true, 'priority' => $index < 4]) ?><?php endforeach; ?>
          </div>
          <div class="mt-4"><?= pagination_links($results['pager'], array_filter([
              'slug' => $slug, 'brand' => $filters['brand'] ?: null, 'min' => $filters['min'], 'max' => $filters['max'],
              'rating' => $filters['rating'], 'in_stock' => $filters['in_stock'] ? 1 : null,
              'on_sale' => $filters['on_sale'] ? 1 : null, 'sort' => $filters['sort'],
          ], static fn ($value) => $value !== null && $value !== '' && $value !== 0)) ?></div>
        <?php endif; ?>
        </div><!-- /#shop-results -->
      </div>
    </div>
  </div>
</section>

<div role="dialog" aria-modal="true" class="offcanvas offcanvas-start" tabindex="-1" id="filterDrawer" aria-labelledby="filterDrawerLabel">
  <div class="offcanvas-header">
    <h2 class="offcanvas-title h6 fw-bold" id="filterDrawerLabel">Filters</h2>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body">
    <?php $baseAction = '/category.php'; $hiddenParams = ['slug' => $slug]; require INCLUDES_PATH . '/partials/shop-filters.php'; ?>
  </div>
</div>

<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

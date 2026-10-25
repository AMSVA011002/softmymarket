<?php
/**
 * Shop / search results with faceted filters.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$filters = Catalog::parseFilters($_GET);
$filters['per'] = 24;
$result = Catalog::paginateProducts($filters);
$facets = Catalog::productFacets($filters);
$products = $result['rows'];
$total = (int) $result['total'];
$term = (string) $filters['q'];

// Log searches that return results (and misses) for merchandising reports.
if ($term !== '' && mb_strlen($term) >= 3 && (int) $filters['page'] === 1) {
    try {
        Search::log($term, $total, Auth::id());
    } catch (Throwable $e) {
        error_log('search log failed: ' . $e->getMessage());
    }
}

$categoryMap = Catalog::categoryMap(true);
$activeCategory = $filters['category'] !== '' ? ($categoryMap[$filters['category']] ?? null) : null;

// Chips describing the active filters, each removable in one tap.
$chips = [];
if ($term !== '') {
    $chips[] = ['label' => 'Search: ' . $term, 'url' => with_query(['q' => null, 'page' => null])];
}
if ($activeCategory !== null) {
    $chips[] = ['label' => $activeCategory['name'], 'url' => with_query(['category' => null, 'page' => null])];
}
foreach ($filters['brand'] as $brandSlug) {
    $remaining = array_values(array_diff($filters['brand'], [$brandSlug]));
    $chips[] = ['label' => humanize($brandSlug), 'url' => with_query(['brand' => $remaining === [] ? null : $remaining, 'page' => null])];
}
if ($filters['min'] !== null || $filters['max'] !== null) {
    $chips[] = ['label' => money($filters['min'] ?? 0) . ' – ' . ($filters['max'] !== null ? money($filters['max']) : 'any'), 'url' => with_query(['min' => null, 'max' => null, 'page' => null])];
}
if ($filters['rating'] !== null) {
    $chips[] = ['label' => $filters['rating'] . '★ & up', 'url' => with_query(['rating' => null, 'page' => null])];
}
if (!empty($filters['in_stock'])) {
    $chips[] = ['label' => 'In stock', 'url' => with_query(['in_stock' => null, 'page' => null])];
}
if (!empty($filters['on_sale'])) {
    $chips[] = ['label' => 'On sale', 'url' => with_query(['on_sale' => null, 'page' => null])];
}
if (!empty($filters['featured'])) {
    $chips[] = ['label' => 'Featured', 'url' => with_query(['featured' => null, 'page' => null])];
}
if (($filters['condition'] ?? '') !== '') {
    $chips[] = ['label' => humanize((string) $filters['condition']), 'url' => with_query(['condition' => null, 'page' => null])];
}

$pageTitle = $term !== '' ? 'Search results for “' . $term . '”' : ($activeCategory !== null ? $activeCategory['name'] : 'Shop all products');
$pageDescription = $term !== ''
    ? 'Products matching “' . $term . '” — ' . number_format($total) . ' results.'
    : 'Browse ' . number_format($total) . ' products across every department, with cash on delivery and island-wide shipping.';
$noindex = $term !== '' || (int) $filters['page'] > 1;
$canonical = $term === '' ? url('/shop.php' . ($activeCategory !== null ? '?category=' . rawurlencode((string) $activeCategory['slug']) : '')) : null;
require INCLUDES_PATH . '/layout/header.php';
?>
<div class="breadcrumb-wrap">
  <div class="container-page">
    <?php
    $crumbs = ['Shop' => '/shop.php'];
    if ($activeCategory !== null) {
        $crumbs = [];
        foreach (Catalog::categoryBreadcrumb($activeCategory) as $node) {
            $crumbs[$node['name']] = '/category.php?slug=' . rawurlencode((string) $node['slug']);
        }
        $crumbs[$activeCategory['name']] = null;
    }
    echo View::breadcrumbs($crumbs);
    ?>
  </div>
</div>

<section class="section pb-0">
  <div class="container-page">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-3">
      <div>
        <h1 class="h4 fw-bold mb-1"><?= e($pageTitle) ?></h1>
        <p class="small text-ink-500 mb-0">
          <?php if ($total > 0): ?>
            Showing <?= (int) $result['pager']['from'] ?>–<?= (int) $result['pager']['to'] ?> of <?= number_format($total) ?> results
          <?php else: ?>
            No products matched your filters
          <?php endif; ?>
        </p>
      </div>
      <div class="d-flex gap-2 align-items-center">
        <button class="btn btn-soft btn-sm d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#filterDrawer">
          <i class="bi bi-funnel me-1"></i> Filters<?= $chips !== [] ? ' (' . count($chips) . ')' : '' ?>
        </button>
        <div style="min-width:190px"><?= View::sortSelect((string) $filters['sort']) ?></div>
      </div>
    </div>

    <?php if ($chips !== []): ?>
      <div class="filter-chips mb-3">
        <?php foreach ($chips as $chip): ?>
          <a class="filter-chip" href="<?= e($chip['url']) ?>"><?= e($chip['label']) ?> <i class="bi bi-x"></i></a>
        <?php endforeach; ?>
        <a class="filter-chip" href="/shop.php" style="background:transparent;border:1px dashed var(--ink-300)">Clear all</a>
      </div>
    <?php endif; ?>

    <div class="row g-3 g-lg-4">
      <aside class="col-lg-3 d-none d-lg-block">
        <?php $baseAction = '/shop.php'; $hiddenParams = []; require INCLUDES_PATH . '/partials/shop-filters.php'; ?>
      </aside>
      <div class="col-lg-9">
        <div id="shop-results" data-live-results>
        <?php if ($products === []): ?>
          <?= View::emptyState('bi-search', 'Nothing matched just yet', 'Try a different search word, widen the price range or clear a filter.', 'Clear filters', '/shop.php') ?>
          <div class="text-center mt-3">
            <p class="small text-ink-500 mb-2">Popular searches</p>
            <div class="d-flex flex-wrap justify-content-center gap-2">
              <?php foreach (Search::trendingTerms(8) as $trend): ?>
                <a class="filter-chip" href="/shop.php?q=<?= rawurlencode((string) $trend['term']) ?>"><?= e($trend['term']) ?></a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php else: ?>
          <div class="grid-products">
            <?php foreach ($products as $index => $product): ?>
              <?= View::card($product, ['showQuickAdd' => true, 'priority' => $index < 4]) ?>
            <?php endforeach; ?>
          </div>
          <div class="mt-4"><?= pagination_links($result['pager'], array_filter([
              'q' => $term, 'category' => $filters['category'], 'brand' => $filters['brand'] === [] ? null : $filters['brand'],
              'min' => $filters['min'], 'max' => $filters['max'], 'rating' => $filters['rating'],
              'in_stock' => !empty($filters['in_stock']) ? 1 : null, 'on_sale' => !empty($filters['on_sale']) ? 1 : null,
              'featured' => !empty($filters['featured']) ? 1 : null, 'condition' => $filters['condition'], 'sort' => $filters['sort'],
          ], static fn ($value) => $value !== null && $value !== '' && $value !== [])) ?></div>
        <?php endif; ?>
        </div><!-- /#shop-results -->
      </div>
    </div>
  </div>
</section>

<div class="offcanvas offcanvas-start" tabindex="-1" id="filterDrawer" aria-labelledby="filterDrawerLabel">
  <div class="offcanvas-header">
    <h2 class="offcanvas-title h6 fw-bold" id="filterDrawerLabel">Filters</h2>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body">
    <?php $baseAction = '/shop.php'; $hiddenParams = []; require INCLUDES_PATH . '/partials/shop-filters.php'; ?>
  </div>
</div>

<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

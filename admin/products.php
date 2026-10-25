<?php
/**
 * Catalogue list with quick filters and inline actions.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
admin_require('products.manage');

$filters = [
    'q' => trim((string) query('q', '')),
    'status' => (string) query('status', ''),
    'category' => query_int('category'),
    'brand' => query_int('brand'),
    'stock' => (string) query('stock', ''),
    'sort' => (string) query('sort', 'newest'),
    'page' => query_int('page', 1),
];
$result = Catalog::adminProducts($filters);
$rows = $result['rows'];
$categories = Catalog::categoryFlat(false);
$brands = Catalog::brands();
$inv = Reports::inventory();
$returnUrl = admin_url('products.php', array_filter($filters, static fn ($value) => $value !== '' && $value !== 0));

$adminTitle = 'Products';
$adminSubtitle = number_format($result['total']) . ' products · ' . number_format($inv['units']) . ' units in stock · retail value ' . money($inv['retail_value']);
$adminActions = '<a class="btn btn-sm btn-soft" href="' . e(admin_url('categories.php')) . '"><i class="bi bi-diagram-3 me-1"></i> Categories</a>'
    . '<a class="btn btn-sm btn-brand" href="' . e(admin_url('product-form.php')) . '"><i class="bi bi-plus-lg me-1"></i> Add product</a>';
require __DIR__ . '/includes/layout-top.php';
?>
<form class="card-soft p-3 mb-3" method="get" action="<?= e(admin_url('products.php')) ?>" data-live-filter data-live-min="0">
  <div class="row g-2 align-items-end">
    <div class="col-12 col-md-4">
      <label class="form-label small mb-1" for="p-q">Search</label>
      <input class="form-control form-control-sm" id="p-q" name="q" type="search" value="<?= e($filters['q']) ?>"
             placeholder="Search title, SKU, brand…" data-live-input autocomplete="off">
      <div class="live-search-note">Filters as you type.</div>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small mb-1" for="p-status">Status</label>
      <select class="form-select form-select-sm" id="p-status" name="status">
        <option value="">Any</option>
        <?php foreach (['active' => 'Active', 'draft' => 'Draft', 'archived' => 'Archived'] as $value => $label): ?>
          <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small mb-1" for="p-category">Category</label>
      <select class="form-select form-select-sm" id="p-category" name="category">
        <option value="">All</option>
        <?php foreach ($categories as $category): ?>
          <option value="<?= (int) $category['id'] ?>" <?= $filters['category'] === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small mb-1" for="p-stock">Stock</label>
      <select class="form-select form-select-sm" id="p-stock" name="stock">
        <option value="">Any</option>
        <option value="low" <?= $filters['stock'] === 'low' ? 'selected' : '' ?>>Low stock</option>
        <option value="out" <?= $filters['stock'] === 'out' ? 'selected' : '' ?>>Out of stock</option>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small mb-1" for="p-brand">Brand</label>
      <select class="form-select form-select-sm" id="p-brand" name="brand">
        <option value="">All</option>
        <?php foreach ($brands as $brand): ?>
          <option value="<?= (int) $brand['id'] ?>" <?= $filters['brand'] === (int) $brand['id'] ? 'selected' : '' ?>><?= e($brand['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12 d-flex gap-2">
      <button class="btn btn-sm btn-brand" type="submit"><i class="bi bi-funnel me-1"></i> Filter</button>
      <a class="btn btn-sm btn-soft" href="<?= e(admin_url('products.php')) ?>">Clear</a>
      <select class="form-select form-select-sm ms-auto" name="sort" style="max-width:170px" data-autosubmit>
        <?php foreach (['newest' => 'Newest', 'title' => 'Title', 'price_asc' => 'Price ↑', 'price_desc' => 'Price ↓', 'stock' => 'Lowest stock', 'sold' => 'Best selling'] as $value => $label): ?>
          <option value="<?= e($value) ?>" <?= $filters['sort'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
</form>

<div data-live-results>
<div class="card-soft p-0">
  <div class="table-responsive">
    <table class="table table-admin mb-0">
      <thead>
        <tr><th>Product</th><th class="d-none d-lg-table-cell">Category</th><th class="text-end">Price</th><th class="text-center">Stock</th><th class="text-center d-none d-md-table-cell">Sold</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $product): ?>
          <tr>
            <td>
              <div class="d-flex align-items-center gap-2">
                <img src="<?= e(img_url($product['image'], (string) $product['title'], 80)) ?>" alt="" width="40" height="40" style="border-radius:.45rem;object-fit:cover;background:var(--ink-100)">
                <div class="min-w-0">
                  <a class="text-decoration-none fw-semibold d-block text-truncate" style="max-width:280px" href="<?= e(admin_url('product-form.php', ['id' => (int) $product['id']])) ?>"><?= e($product['title']) ?></a>
                  <div class="text-ink-400" style="font-size:.68rem"><?= e($product['sku']) ?><?= !empty($product['brand_name']) ? ' · ' . e($product['brand_name']) : '' ?></div>
                </div>
              </div>
            </td>
            <td class="d-none d-lg-table-cell small"><?= e((string) ($product['category_name'] ?? '—')) ?></td>
            <td class="text-end small text-nowrap">
              <?= e(money($product['price'])) ?>
              <?php if (!empty($product['compare_at_price'])): ?><div class="text-ink-400 text-decoration-line-through" style="font-size:.68rem"><?= e(money($product['compare_at_price'])) ?></div><?php endif; ?>
            </td>
            <td class="text-center">
              <span class="badge text-bg-<?= (int) $product['stock'] <= 0 ? 'danger' : ((int) $product['stock'] <= (int) $product['low_stock_alert'] ? 'warning' : 'light') ?>"><?= (int) $product['stock'] ?></span>
            </td>
            <td class="text-center d-none d-md-table-cell small"><?= (int) $product['sold_count'] ?></td>
            <td>
              <span class="badge text-bg-<?= $product['status'] === 'active' ? 'success' : ($product['status'] === 'draft' ? 'secondary' : 'dark') ?>"><?= e(humanize((string) $product['status'])) ?></span>
              <?php if (!empty($product['is_featured'])): ?><span class="badge text-bg-light" title="Featured"><i class="bi bi-star-fill text-warning"></i></span><?php endif; ?>
            </td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-soft" href="<?= e('/product.php?slug=' . rawurlencode((string) $product['slug'])) ?>" target="_blank" rel="noopener" title="View on site"><i class="bi bi-box-arrow-up-right"></i></a>
              <a class="btn btn-sm btn-soft" href="<?= e(admin_url('product-form.php', ['id' => (int) $product['id']])) ?>" title="Edit"><i class="bi bi-pencil"></i></a>
              <form method="post" action="/admin/actions.php" class="d-inline" data-confirm="Archive “<?= e($product['title']) ?>”? It will disappear from the shop but order history is kept.">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="product_delete">
                <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                <button class="btn btn-sm btn-soft" type="submit" title="Archive"><i class="bi bi-archive"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($rows === []): ?>
          <tr><td colspan="7" class="text-center small text-ink-500 py-5"><i class="bi bi-bag fs-4 d-block mb-2"></i>No products match. <a href="<?= e(admin_url('product-form.php')) ?>">Add the first one</a>.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="mt-3"><?= pagination_links($result['pager'], array_filter($filters, static fn ($value) => $value !== '' && $value !== 0)) ?></div>
</div><!-- /data-live-results -->

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>

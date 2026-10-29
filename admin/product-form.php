<?php
/**
 * Create / edit a product.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
admin_require('products.manage');

$id = query_int('id');
$product = $id > 0 ? Catalog::product($id) : null;
if ($id > 0 && $product === null) {
    flash('danger', 'Product not found.');
    redirect(admin_url('products.php'));
}

$values = [
    'title' => old('title', $product['title'] ?? ''),
    'slug' => old('slug', $product['slug'] ?? ''),
    'sku' => old('sku', $product['sku'] ?? ''),
    'category_id' => (int) old('category_id', $product['category_id'] ?? 0),
    'parent_category_id' => (int) old('parent_category_id', 0),
    'brand_id' => (int) old('brand_id', $product['brand_id'] ?? 0),
    'short_description' => old('short_description', $product['short_description'] ?? ''),
    'description' => old('description', $product['description'] ?? ''),
    'price' => old('price', $product['price'] ?? ''),
    'compare_at_price' => old('compare_at_price', $product['compare_at_price'] ?? ''),
    'cost_price' => old('cost_price', $product['cost_price'] ?? ''),
    'stock' => old('stock', $product['stock'] ?? 0),
    'low_stock_alert' => old('low_stock_alert', $product['low_stock_alert'] ?? Settings::int('low_stock_threshold', 5)),
    'condition' => old('condition', $product['condition'] ?? 'new'),
    'status' => old('status', $product['status'] ?? 'draft'),
    'warranty' => old('warranty', $product['warranty'] ?? ''),
    'weight_grams' => old('weight_grams', $product['weight_grams'] ?? ''),
    'shipping_fee' => old('shipping_fee', $product['shipping_fee'] ?? ''),
    'meta_title' => old('meta_title', $product['meta_title'] ?? ''),
    'meta_description' => old('meta_description', $product['meta_description'] ?? ''),
    'tags' => old('tags', implode(', ', $product['tags_array'] ?? [])),
];
$categoryOptions = Catalog::categories(true);
$categoryById = [];
foreach ($categoryOptions as $categoryOption) {
    $categoryById[(int) $categoryOption['id']] = $categoryOption;
}
$selectedCategory = $categoryById[$values['category_id']] ?? null;
if ((int) $values['parent_category_id'] <= 0) {
    // A product in a subcategory uses its immediate parent; a product in a
    // top-level category uses that category as its own parent selection.
    $values['parent_category_id'] = $selectedCategory !== null && !empty($selectedCategory['parent_id'])
        ? (int) $selectedCategory['parent_id']
        : (int) ($selectedCategory['id'] ?? 0);
}
$flags = [
    'is_choice' => (int) old('is_choice', $product['is_choice'] ?? 0),
    'is_featured' => (int) old('is_featured', $product['is_featured'] ?? 0),
    'is_flash_deal' => (int) old('is_flash_deal', $product['is_flash_deal'] ?? 0),
    'free_shipping' => (int) old('free_shipping', $product['free_shipping'] ?? 0),
];
// One newline-separated field replaces the old primary + per-slot + paste fields.
// Preserve all legacy image links when editing a product.
$existingImages = is_array($product['images'] ?? null) ? $product['images'] : [];
$imageLinksValue = old('image_links', implode("\n", $existingImages));
$specs = $product['specs'] ?? [];
if (!is_array($specs) || $specs === []) {
    $specs = ['' => ''];
}
$returnUrl = admin_url('product-form.php' . ($product !== null ? '?id=' . (int) $product['id'] : ''));

$adminTitle = $product === null ? 'Add product' : 'Edit product';
$adminSubtitle = $product === null ? 'Create a new listing' : $product['title'];
$adminActions = '<a class="btn btn-sm btn-soft" href="' . e(admin_url('products.php')) . '"><i class="bi bi-arrow-left me-1"></i> All products</a>'
    . ($product !== null ? '<a class="btn btn-sm btn-soft" href="' . e('/product.php?slug=' . rawurlencode((string) $product['slug'])) . '" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i> View</a>' : '');
require __DIR__ . '/includes/layout-top.php';
?>
<form method="post" action="/admin/actions.php" enctype="multipart/form-data" data-disable-on-submit>
  <?= Csrf::field() ?>
  <input type="hidden" name="action" value="product_save">
  <input type="hidden" name="id" value="<?= (int) ($product['id'] ?? 0) ?>">
  <input type="hidden" name="return" value="<?= e($returnUrl) ?>">

  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card-soft p-3 mb-3">
        <h2 class="h6 fw-bold mb-3">Basics</h2>
        <div class="row g-2">
          <div class="col-12">
            <label class="form-label small" for="title">Product title *</label>
            <input class="form-control" id="title" name="title" value="<?= e($values['title']) ?>" maxlength="200" required>
          </div>
          <div class="col-md-6">
            <label class="form-label small" for="sku">SKU *</label>
            <input class="form-control" id="sku" name="sku" value="<?= e($values['sku']) ?>" maxlength="60" required>
          </div>
          <div class="col-md-6">
            <label class="form-label small" for="slug">URL slug</label>
            <input class="form-control" id="slug" name="slug" value="<?= e($values['slug']) ?>" maxlength="220" placeholder="auto from the title">
          </div>
          <div class="col-md-6">
            <label class="form-label small" for="parent_category_id">Parent category *</label>
            <select class="form-select" id="parent_category_id" name="parent_category_id" data-category-parent required>
              <option value="">Choose a parent…</option>
              <?php foreach ($categoryOptions as $categoryOption): ?>
                <option value="<?= (int) $categoryOption['id'] ?>" <?= $values['parent_category_id'] === (int) $categoryOption['id'] ? 'selected' : '' ?>><?= e(str_repeat('— ', (int) ($categoryOption['depth'] ?? 0)) . $categoryOption['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label small" for="category_id">Subcategory / final category *</label>
            <select class="form-select" id="category_id" name="category_id" data-category-child required>
              <option value="">Choose a parent category first…</option>
              <?php foreach ($categoryOptions as $parentOption): ?>
                <optgroup label="<?= e($parentOption['name']) ?>" data-parent-group="<?= (int) $parentOption['id'] ?>">
                  <option value="<?= (int) $parentOption['id'] ?>" data-parent-id="<?= (int) $parentOption['id'] ?>" <?= $values['category_id'] === (int) $parentOption['id'] ? 'selected' : '' ?>>Use <?= e($parentOption['name']) ?> (no subcategory)</option>
                  <?php foreach ($categoryOptions as $childOption): ?>
                    <?php if ((int) ($childOption['parent_id'] ?? 0) === (int) $parentOption['id']): ?>
                      <option value="<?= (int) $childOption['id'] ?>" data-parent-id="<?= (int) $parentOption['id'] ?>" <?= $values['category_id'] === (int) $childOption['id'] ? 'selected' : '' ?>><?= e($childOption['name']) ?></option>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </optgroup>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Choose the parent first, then choose a subcategory. Select “Use parent” when there is no subcategory.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label small" for="brand_id">Brand</label>
            <select class="form-select" id="brand_id" name="brand_id">
              <option value="">No brand</option>
              <?php foreach (Catalog::brands() as $brand): ?>
                <option value="<?= (int) $brand['id'] ?>" <?= $values['brand_id'] === (int) $brand['id'] ? 'selected' : '' ?>><?= e($brand['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label small" for="short_description">Short description</label>
            <textarea class="form-control" id="short_description" name="short_description" rows="2" maxlength="320"><?= e($values['short_description']) ?></textarea>
            <div class="form-text">Shown on cards and search results. Keep it under 160 characters.</div>
          </div>
          <div class="col-12">
            <label class="form-label small" for="description">Full description</label>
            <textarea class="form-control" id="description" name="description" rows="7"><?= e($values['description']) ?></textarea>
          </div>
        </div>
      </div>

      <div class="card-soft p-3 mb-3">
        <h2 class="h6 fw-bold mb-3">Pricing &amp; stock</h2>
        <div class="row g-2">
          <div class="col-6 col-md-3">
            <label class="form-label small" for="price">Price (Rs) *</label>
            <input class="form-control" id="price" name="price" type="number" min="0" step="0.01" value="<?= e((string) $values['price']) ?>" required>
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label small" for="compare_at_price">Was price</label>
            <input class="form-control" id="compare_at_price" name="compare_at_price" type="number" min="0" step="0.01" value="<?= e((string) $values['compare_at_price']) ?>">
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label small" for="cost_price">Cost price</label>
            <input class="form-control" id="cost_price" name="cost_price" type="number" min="0" step="0.01" value="<?= e((string) $values['cost_price']) ?>">
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label small" for="shipping_fee">Item shipping</label>
            <input class="form-control" id="shipping_fee" name="shipping_fee" type="number" min="0" step="0.01" value="<?= e((string) $values['shipping_fee']) ?>">
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label small" for="stock">Stock on hand *</label>
            <input class="form-control" id="stock" name="stock" type="number" min="0" step="1" value="<?= e((string) $values['stock']) ?>" required>
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label small" for="low_stock_alert">Low stock alert</label>
            <input class="form-control" id="low_stock_alert" name="low_stock_alert" type="number" min="0" step="1" value="<?= e((string) $values['low_stock_alert']) ?>">
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label small" for="weight_grams">Weight (g)</label>
            <input class="form-control" id="weight_grams" name="weight_grams" type="number" min="0" step="1" value="<?= e((string) $values['weight_grams']) ?>">
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label small" for="condition">Condition</label>
            <select class="form-select" id="condition" name="condition">
              <?php foreach (['new' => 'Brand new', 'used' => 'Used', 'refurbished' => 'Refurbished'] as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $values['condition'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <?php if ($product !== null && (int) $product['stock'] !== (int) $values['stock']): ?>
          <p class="form-text mb-0">Changing stock here records an inventory movement automatically.</p>
        <?php endif; ?>
      </div>

      <div class="card-soft p-3 mb-3">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <h2 class="h6 fw-bold mb-0">Product images</h2>
          <span class="badge text-bg-light" data-gallery-count>0 / 20</span>
        </div>
        <p class="small text-ink-500 mb-2">Paste up to <strong>20 image links</strong>, one per line. The first link is the main image; any remaining links appear in the product gallery. Public Google Drive share links and normal https image URLs work.</p>
        <label class="form-label small" for="image_links">Image links</label>
        <textarea class="form-control" id="image_links" name="image_links" rows="5" maxlength="40000"
                  placeholder="https://example.com/product-main.jpg&#10;https://example.com/product-side.jpg"
                  data-image-links><?= e((string) $imageLinksValue) ?></textarea>
        <div class="form-text">Add or remove lines; only valid links entered here are saved. The preview appears below.</div>
        <div class="image-links-preview mt-3" data-image-preview-grid aria-live="polite"></div>
        <div class="form-text mt-2" data-image-links-status></div>
      </div>

      <div class="card-soft p-3 mb-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h2 class="h6 fw-bold mb-0">Specifications</h2>
          <button class="btn btn-sm btn-soft" type="button" data-spec-add><i class="bi bi-plus-lg"></i> Add row</button>
        </div>
        <div data-spec-rows>
          <?php foreach ($specs as $name => $value): ?>
            <div class="row g-2 mb-2" data-spec-row>
              <div class="col-5"><input class="form-control form-control-sm" name="spec_name[]" value="<?= e((string) $name) ?>" placeholder="e.g. Screen size"></div>
              <div class="col-6"><input class="form-control form-control-sm" name="spec_value[]" value="<?= e((string) (is_array($value) ? implode(', ', $value) : $value)) ?>" placeholder="e.g. 6.7 inches"></div>
              <div class="col-1 text-end"><button class="btn btn-sm btn-soft" type="button" data-spec-remove aria-label="Remove"><i class="bi bi-x"></i></button></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card-soft p-3 mb-3">
        <h2 class="h6 fw-bold mb-2">Publishing</h2>
        <label class="form-label small" for="status">Status</label>
        <select class="form-select form-select-sm mb-2" id="status" name="status">
          <?php foreach (['active' => 'Active — visible in the shop', 'draft' => 'Draft — hidden', 'archived' => 'Archived / discontinued — hidden from the store'] as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= $values['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="is_choice" name="is_choice" value="1" <?= $flags['is_choice'] ? 'checked' : '' ?>>
          <label class="form-check-label small" for="is_choice">Show “Choice” badge on product cards</label>
        </div>
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="is_featured" name="is_featured" value="1" <?= $flags['is_featured'] ? 'checked' : '' ?>>
          <label class="form-check-label small" for="is_featured">Featured on the home page</label>
        </div>
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="is_flash_deal" name="is_flash_deal" value="1" <?= $flags['is_flash_deal'] ? 'checked' : '' ?>>
          <label class="form-check-label small" for="is_flash_deal">Show in flash deals</label>
        </div>
        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" id="free_shipping" name="free_shipping" value="1" <?= $flags['free_shipping'] ? 'checked' : '' ?>>
          <label class="form-check-label small" for="free_shipping">Free shipping for this item</label>
        </div>
        <button class="btn btn-brand w-100" type="submit"><i class="bi bi-check2 me-1"></i> <?= $product === null ? 'Create product' : 'Save changes' ?></button>
      </div>

      <div class="card-soft p-3 mb-3">
        <h2 class="h6 fw-bold mb-2">Warranty &amp; tags</h2>
        <label class="form-label small" for="warranty">Warranty</label>
        <input class="form-control form-control-sm mb-2" id="warranty" name="warranty" value="<?= e($values['warranty']) ?>" placeholder="1 year local warranty" maxlength="120">
        <label class="form-label small" for="tags">Tags</label>
        <input class="form-control form-control-sm" id="tags" name="tags" value="<?= e($values['tags']) ?>" placeholder="phone, android, 5g">
        <div class="form-text">Comma separated; used by search.</div>
      </div>

      <div class="card-soft p-3 mb-3">
        <h2 class="h6 fw-bold mb-2">SEO</h2>
        <label class="form-label small" for="meta_title">Meta title</label>
        <input class="form-control form-control-sm mb-2" id="meta_title" name="meta_title" value="<?= e($values['meta_title']) ?>" maxlength="180">
        <label class="form-label small" for="meta_description">Meta description</label>
        <textarea class="form-control form-control-sm" id="meta_description" name="meta_description" rows="3" maxlength="320"><?= e($values['meta_description']) ?></textarea>
      </div>

      <?php if ($product !== null): ?>
        <div class="card-soft p-3">
          <h2 class="h6 fw-bold mb-2">Danger zone</h2>
          <p class="small text-ink-500">Archiving hides the product but keeps every order and review intact.</p>
          <?php /* The button submits the standalone form below. A nested <form> is
                   invalid HTML: the browser drops the inner tag, so its hidden
                   fields used to be posted with "Save changes" too — PHP kept the
                   last "action" value, which archived the product on every save. */ ?>
          <button class="btn btn-outline-danger btn-sm w-100" type="submit" form="product-archive-form">
            <i class="bi bi-archive me-1"></i> Archive product
          </button>
        </div>
      <?php endif; ?>
    </div>
  </div>
</form>

<?php if ($product !== null): ?>
  <form id="product-archive-form" method="post" action="/admin/actions.php" class="d-none"
        data-confirm="Archive this product? It disappears from the shop, but orders and reviews are kept.">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="product_delete">
    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
    <input type="hidden" name="return" value="<?= e(admin_url('products.php')) ?>">
  </form>
<?php endif; ?>

<?php
$adminInlineScript = <<<'JS'
(function () {
  var rows = document.querySelector('[data-spec-rows]');
  var add = document.querySelector('[data-spec-add]');
  if (add && rows) {
    add.addEventListener('click', function () {
      var row = rows.querySelector('[data-spec-row]').cloneNode(true);
      row.querySelectorAll('input').forEach(function (input) { input.value = ''; });
      rows.appendChild(row);
    });
  }
  document.addEventListener('click', function (event) {
    var remove = event.target.closest('[data-spec-remove]');
    if (remove) {
      var row = remove.closest('[data-spec-row]');
      var list = document.querySelector('[data-spec-rows]');
      if (row && list && list.querySelectorAll('[data-spec-row]').length > 1) row.remove();
    }
  });
})();
JS;
require __DIR__ . '/includes/layout-bottom.php';
?>

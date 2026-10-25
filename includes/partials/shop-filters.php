<?php
/**
 * Faceted filter panel used by shop.php, category.php and deals.php.
 *
 * Expects:
 *   $filters  array  Catalog::parseFilters() output
 *   $facets   array  Catalog::productFacets() output
 *   $baseAction string  form target (defaults to /shop.php)
 *   $hiddenParams array extra hidden inputs (e.g. category slug)
 */
$filters = $filters ?? [];
$facets = $facets ?? ['brands' => [], 'min_price' => 0, 'max_price' => 0];
$baseAction = $baseAction ?? '/shop.php';
$hiddenParams = $hiddenParams ?? [];
$selectedBrands = (array) ($filters['brand'] ?? []);
$selectedCategory = (string) ($filters['category'] ?? '');
$categories = Catalog::categories(true);
$byParent = [];
foreach ($categories as $category) {
    $byParent[(int) ($category['parent_id'] ?? 0)][] = $category;
}
$currentSort = (string) ($filters['sort'] ?? 'newest');
/** Render one level of the category tree as radio options. */
$renderTree = static function (array $nodes, int $level) use (&$renderTree, $byParent, $selectedCategory): void {
    foreach ($nodes as $node) {
        $children = $byParent[(int) $node['id']] ?? [];
        ?>
        <div class="filter-option" style="padding-left:<?= $level * 0.85 ?>rem">
          <input class="form-check-input" type="radio" name="category" id="cat-<?= (int) $node['id'] ?>"
                 value="<?= e($node['slug']) ?>" <?= $selectedCategory === $node['slug'] ? 'checked' : '' ?>>
          <label class="form-check-label flex-grow-1" for="cat-<?= (int) $node['id'] ?>"><?= e($node['name']) ?></label>
        </div>
        <?php
        if ($children !== [] && $level < 3) {
            $renderTree($children, $level + 1);
        }
    }
};
?>
<form class="card-soft p-3 filter-panel" method="get" action="<?= e($baseAction) ?>" data-filters-form>
  <?php foreach ($hiddenParams as $name => $value): ?>
    <input type="hidden" name="<?= e((string) $name) ?>" value="<?= e((string) $value) ?>">
  <?php endforeach; ?>

  <div class="d-flex align-items-center justify-content-between mb-2">
    <h2 class="h6 fw-bold mb-0"><i class="bi bi-funnel me-1"></i> Filters</h2>
    <a class="small text-decoration-none" href="<?= e($baseAction . ($hiddenParams !== [] ? '?' . http_build_query($hiddenParams) : '')) ?>">Reset</a>
  </div>

  <?php if (!empty($filters['q'])): ?>
    <input type="hidden" name="q" value="<?= e($filters['q']) ?>">
  <?php endif; ?>

  <?php if ($categories !== []): ?>
    <div class="filter-group">
      <div class="filter-title">Category</div>
      <div class="filter-scroll">
        <div class="filter-option">
          <input class="form-check-input" type="radio" name="category" id="cat-all" value="" <?= $selectedCategory === '' ? 'checked' : '' ?>>
          <label class="form-check-label" for="cat-all">All categories</label>
        </div>
        <?php $renderTree($byParent[0] ?? [], 0); ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="filter-group">
    <div class="filter-title">Price (<?= e(Settings::get('currency_symbol', 'Rs')) ?>)</div>
    <div class="d-flex gap-2 align-items-center">
      <input class="form-control form-control-sm" type="number" name="min" min="0" step="50"
             value="<?= $filters['min'] !== null ? e((string) (int) $filters['min']) : '' ?>" placeholder="<?= (int) ($facets['min_price'] ?? 0) ?>" aria-label="Minimum price">
      <span class="text-ink-400">–</span>
      <input class="form-control form-control-sm" type="number" name="max" min="0" step="50"
             value="<?= $filters['max'] !== null ? e((string) (int) $filters['max']) : '' ?>" placeholder="<?= (int) ($facets['max_price'] ?? 0) ?>" aria-label="Maximum price">
    </div>
    <div class="d-flex flex-wrap gap-1 mt-2">
      <?php foreach ([[null, 1000], [1000, 5000], [5000, 15000], [15000, 50000], [50000, null]] as [$low, $high]): ?>
        <a class="filter-chip" href="<?= e(with_query(['min' => $low, 'max' => $high, 'page' => null])) ?>">
          <?= $low === null ? 'Under ' . money($high) : ($high === null ? money($low) . '+' : money($low) . '–' . money($high)) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (!empty($facets['brands'])): ?>
    <div class="filter-group">
      <div class="filter-title">Brand</div>
      <div class="filter-scroll">
        <?php foreach ($facets['brands'] as $brand): ?>
          <label class="filter-option">
            <input class="form-check-input" type="checkbox" name="brand[]" value="<?= e($brand['slug']) ?>" <?= in_array($brand['slug'], $selectedBrands, true) ? 'checked' : '' ?>>
            <span class="flex-grow-1"><?= e((string) ($brand['name'] ?? $brand['slug'] ?? '')) ?></span>
            <span class="count"><?= (int) $brand['total'] ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="filter-group">
    <div class="filter-title">Customer rating</div>
    <?php foreach ([4, 3, 2, 1] as $rating): ?>
      <label class="filter-option">
        <input class="form-check-input" type="radio" name="rating" value="<?= $rating ?>" <?= (int) ($filters['rating'] ?? 0) === $rating ? 'checked' : '' ?>>
        <span class="flex-grow-1"><?= View::stars((float) $rating) ?> &amp; up</span>
      </label>
    <?php endforeach; ?>
  </div>

  <div class="filter-group">
    <div class="filter-title">Availability</div>
    <label class="filter-option">
      <input class="form-check-input" type="checkbox" name="in_stock" value="1" <?= !empty($filters['in_stock']) ? 'checked' : '' ?>>
      <span>In stock only</span>
    </label>
    <label class="filter-option">
      <input class="form-check-input" type="checkbox" name="on_sale" value="1" <?= !empty($filters['on_sale']) ? 'checked' : '' ?>>
      <span>On sale</span>
    </label>
    <label class="filter-option">
      <input class="form-check-input" type="checkbox" name="featured" value="1" <?= !empty($filters['featured']) ? 'checked' : '' ?>>
      <span>Featured</span>
    </label>
  </div>

  <div class="filter-group">
    <div class="filter-title">Condition</div>
    <?php foreach (['' => 'Any', 'new' => 'Brand new', 'used' => 'Used', 'refurbished' => 'Refurbished'] as $value => $label): ?>
      <label class="filter-option">
        <input class="form-check-input" type="radio" name="condition" value="<?= e($value) ?>" <?= (string) ($filters['condition'] ?? '') === $value ? 'checked' : '' ?>>
        <span><?= e($label) ?></span>
      </label>
    <?php endforeach; ?>
  </div>

  <input type="hidden" name="sort" value="<?= e($currentSort) ?>">
  <div class="d-grid gap-2 mt-2">
    <button class="btn btn-brand btn-sm" type="submit"><i class="bi bi-check2 me-1"></i> Apply filters</button>
  </div>
</form>

<?php
/**
 * Category tree management (adjacency list, unlimited depth).
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
admin_require('products.manage');

$all = Catalog::categories(false);
$counts = Catalog::categoryCounts(false);
$byParent = [];
foreach ($all as $category) {
    $byParent[(int) ($category['parent_id'] ?? 0)][] = $category;
}
$editing = query_int('edit') > 0 ? Catalog::categoryById(query_int('edit')) : null;
$returnUrl = admin_url('categories.php');

$adminTitle = 'Categories';
$adminSubtitle = count($all) . ' categories across ' . count($byParent[0] ?? []) . ' departments';
require __DIR__ . '/includes/layout-top.php';
?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card-soft p-0">
      <div class="px-3 pt-3 pb-2 d-flex flex-wrap gap-2 align-items-center">
        <h2 class="h6 fw-bold mb-0">Tree</h2>
        <input class="form-control form-control-sm ms-auto" style="max-width:240px" type="search"
               placeholder="Filter categories" data-live-client="#categories-tree"
               data-live-empty="#categories-empty" data-live-count="#categories-count"
               aria-label="Filter categories" autocomplete="off">
        <span class="live-search-note" id="categories-count"></span>
      </div>
      <div class="table-responsive" id="categories-tree">
        <table class="table table-admin mb-0">
          <thead><tr><th>Category</th><th class="text-center">Products</th><th class="text-center">Active</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
            <?php
            $renderRows = static function (array $nodes, int $depth) use (&$renderRows, $byParent, $counts, $returnUrl): void {
                foreach ($nodes as $node) {
                    $children = $byParent[(int) $node['id']] ?? [];
                    ?>
                    <tr data-filter-row <?= $depth === 0 ? 'data-filter-parent' : 'data-filter-child' ?>>
                      <td>
                        <div class="d-flex align-items-center gap-2" style="padding-left:<?= $depth * 1.1 ?>rem">
                          <i class="bi <?= e($node['icon'] ?: 'bi-grid') ?>" style="color:<?= e($node['color'] ?: '#0c800f') ?>"></i>
                          <span class="fw-semibold"><?= e($node['name']) ?></span>
                          <?php if ($children !== []): ?><span class="badge text-bg-light"><?= count($children) ?></span><?php endif; ?>
                          <?php if ((int) $node['is_featured'] === 1): ?><i class="bi bi-star-fill text-warning" title="Featured"></i><?php endif; ?>
                          <span class="text-ink-400" style="font-size:.68rem">/<?= e($node['slug']) ?></span>
                        </div>
                      </td>
                      <td class="text-center"><?= (int) ($counts[(int) $node['id']] ?? 0) ?></td>
                      <td class="text-center"><?= (int) $node['is_active'] === 1 ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-dash-circle text-ink-300"></i>' ?></td>
                      <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-soft" href="<?= e(admin_url('categories.php', ['edit' => (int) $node['id']])) ?>" title="Edit"><i class="bi bi-pencil"></i></a>
                        <a class="btn btn-sm btn-soft" href="<?= e('/category.php?slug=' . rawurlencode((string) $node['slug'])) ?>" target="_blank" rel="noopener" title="View"><i class="bi bi-box-arrow-up-right"></i></a>
                        <form method="post" action="/admin/actions.php" class="d-inline" data-confirm="Delete “<?= e($node['name']) ?>”? Products must be moved first.">
                          <?= Csrf::field() ?>
                          <input type="hidden" name="action" value="category_delete">
                          <input type="hidden" name="category_id" value="<?= (int) $node['id'] ?>">
                          <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                          <button class="btn btn-sm btn-soft" type="submit" title="Delete"><i class="bi bi-trash"></i></button>
                        </form>
                      </td>
                    </tr>
                    <?php
                    if ($children !== []) {
                        $renderRows($children, $depth + 1);
                    }
                }
            };
            $renderRows($byParent[0] ?? [], 0);
            ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <form class="card-soft p-3" method="post" action="/admin/actions.php">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="category_save">
      <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
      <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 fw-bold mb-0"><?= $editing === null ? 'Add a category' : 'Edit “' . e($editing['name']) . '”' ?></h2>
        <?php if ($editing !== null): ?><a class="small" href="<?= e($returnUrl) ?>">Cancel</a><?php endif; ?>
      </div>
      <label class="form-label small" for="c-name">Name *</label>
      <input class="form-control form-control-sm mb-2" id="c-name" name="name" value="<?= e($editing['name'] ?? '') ?>" required maxlength="120">
      <label class="form-label small" for="c-parent">Parent</label>
      <select class="form-select form-select-sm mb-2" id="c-parent" name="parent_id">
        <option value="">— Top level —</option>
        <?php foreach (Catalog::categoryFlat(false, $editing !== null ? (int) $editing['id'] : null) as $option): ?>
          <option value="<?= (int) $option['id'] ?>" <?= (int) ($editing['parent_id'] ?? 0) === (int) $option['id'] ? 'selected' : '' ?>><?= e($option['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="row g-2">
        <div class="col-6">
          <label class="form-label small" for="c-icon">Icon (Bootstrap Icons)</label>
          <input class="form-control form-control-sm" id="c-icon" name="icon" value="<?= e($editing['icon'] ?? 'bi-grid') ?>" placeholder="bi-phone">
        </div>
        <div class="col-6">
          <label class="form-label small" for="c-color">Colour</label>
          <input class="form-control form-control-sm form-control-color" id="c-color" name="color" type="color" value="<?= e($editing['color'] ?? '#0c800f') ?>">
        </div>
        <div class="col-6">
          <label class="form-label small" for="c-position">Position</label>
          <input class="form-control form-control-sm" id="c-position" name="position" type="number" min="0" value="<?= e((string) ($editing['position'] ?? 0)) ?>">
        </div>
        <div class="col-6">
          <label class="form-label small" for="c-slug">Slug</label>
          <input class="form-control form-control-sm" id="c-slug" name="slug" value="<?= e($editing['slug'] ?? '') ?>" placeholder="auto">
        </div>
      </div>
      <label class="form-label small mt-2" for="c-image">Image URL</label>
      <input class="form-control form-control-sm mb-2" id="c-image" name="image_url" value="<?= e($editing['image_url'] ?? '') ?>" placeholder="https://…">
      <label class="form-label small" for="c-description">Description</label>
      <textarea class="form-control form-control-sm mb-2" id="c-description" name="description" rows="2"><?= e($editing['description'] ?? '') ?></textarea>
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" id="c-featured" name="is_featured" value="1" <?= (int) ($editing['is_featured'] ?? 0) === 1 ? 'checked' : '' ?>>
        <label class="form-check-label small" for="c-featured">Featured on the home page</label>
      </div>
      <div class="form-check form-switch mb-3">
        <input class="form-check-input" type="checkbox" id="c-active" name="is_active" value="1" <?= ($editing === null || (int) $editing['is_active'] === 1) ? 'checked' : '' ?>>
        <label class="form-check-label small" for="c-active">Visible in the shop</label>
      </div>
      <button class="btn btn-brand btn-sm w-100" type="submit"><?= $editing === null ? 'Create category' : 'Save changes' ?></button>
    </form>
  </div>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>

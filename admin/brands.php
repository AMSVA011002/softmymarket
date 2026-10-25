<?php
/**
 * Brand management.
 */
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
admin_require('products.manage');

$brands = Catalog::brands(true);
$editing = query_int('edit') > 0 ? Catalog::brandById(query_int('edit')) : null;
$returnUrl = admin_url('brands.php');

$adminTitle = 'Brands';
$adminSubtitle = count($brands) . ' brands';
require __DIR__ . '/includes/layout-top.php';
?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card-soft p-0">
      <div class="px-3 pt-3 pb-2 d-flex flex-wrap gap-2 align-items-center">
        <h2 class="h6 fw-bold mb-0">Brands</h2>
        <input class="form-control form-control-sm ms-auto" style="max-width:240px" type="search"
               placeholder="Filter brands" data-live-client="#brands-table"
               data-live-empty="#brands-empty" data-live-count="#brands-count"
               aria-label="Filter brands" autocomplete="off">
        <span class="live-search-note" id="brands-count"></span>
      </div>
      <div class="table-responsive" id="brands-table">
        <table class="table table-admin mb-0">
          <thead><tr><th>Brand</th><th class="text-center">Products</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
            <?php foreach ($brands as $brand): ?>
              <tr data-filter-row>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <?php if (!empty($brand['logo_url'])): ?>
                      <img src="<?= e(img_url((string) $brand['logo_url'], (string) $brand['name'], 80)) ?>" alt="" width="32" height="32" style="border-radius:.4rem;object-fit:contain;background:#fff">
                    <?php else: ?>
                      <span class="logo-mark" style="width:32px;height:32px;font-size:.65rem"><?= e(initials((string) $brand['name'], 2)) ?></span>
                    <?php endif; ?>
                    <div>
                      <div class="fw-semibold"><?= e($brand['name']) ?></div>
                      <div class="text-ink-400" style="font-size:.68rem">/<?= e($brand['slug']) ?></div>
                    </div>
                  </div>
                </td>
                <td class="text-center"><?= (int) ($brand['product_count'] ?? 0) ?></td>
                <td class="text-end text-nowrap">
                  <a class="btn btn-sm btn-soft" href="<?= e(admin_url('brands.php', ['edit' => (int) $brand['id']])) ?>"><i class="bi bi-pencil"></i></a>
                  <a class="btn btn-sm btn-soft" href="<?= e('/shop.php?brand[]=' . rawurlencode((string) $brand['slug'])) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i></a>
                  <form method="post" action="/admin/actions.php" class="d-inline" data-confirm="Delete this brand? Products keep their other data.">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="brand_delete">
                    <input type="hidden" name="brand_id" value="<?= (int) $brand['id'] ?>">
                    <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                    <button class="btn btn-sm btn-soft" type="submit"><i class="bi bi-trash"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if ($brands === []): ?><tr><td colspan="3" class="text-center small text-ink-500 py-4">No brands yet.</td></tr><?php endif; ?>
            <tr id="brands-empty" class="d-none"><td colspan="3" class="text-center small text-ink-500 py-4">No brand matches that search.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <form class="card-soft p-3" method="post" action="/admin/actions.php">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="brand_save">
      <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
      <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 fw-bold mb-0"><?= $editing === null ? 'Add a brand' : 'Edit “' . e($editing['name']) . '”' ?></h2>
        <?php if ($editing !== null): ?><a class="small" href="<?= e($returnUrl) ?>">Cancel</a><?php endif; ?>
      </div>
      <label class="form-label small" for="b-name">Name *</label>
      <input class="form-control form-control-sm mb-2" id="b-name" name="name" value="<?= e($editing['name'] ?? '') ?>" required maxlength="120">
      <label class="form-label small" for="b-slug">Slug</label>
      <input class="form-control form-control-sm mb-2" id="b-slug" name="slug" value="<?= e($editing['slug'] ?? '') ?>" placeholder="auto">
      <label class="form-label small" for="b-logo">Logo URL</label>
      <input class="form-control form-control-sm mb-3" id="b-logo" name="logo_url" value="<?= e($editing['logo_url'] ?? '') ?>" placeholder="https://…">
      <button class="btn btn-brand btn-sm w-100" type="submit"><?= $editing === null ? 'Create brand' : 'Save changes' ?></button>
    </form>
  </div>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>

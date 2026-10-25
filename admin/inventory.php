<?php
/**
 * Stock control: adjust quantities, watch movements, spot shortages.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
admin_require('inventory.manage');

$filter = (string) query('filter', '');
$search = trim((string) query('q', ''));
$where = ['p.deleted_at IS NULL'];
$params = [];
if ($filter === 'low') {
    $where[] = 'p.stock > 0 AND p.stock <= p.low_stock_alert';
} elseif ($filter === 'out') {
    $where[] = 'p.stock <= 0';
}
if ($search !== '') {
    $where[] = '(p.title LIKE :q1 OR p.sku LIKE :q2)';
    $params['q1'] = '%' . $search . '%';
    $params['q2'] = '%' . $search . '%';
}
$whereSql = implode(' AND ', $where);
$total = Database::int("SELECT COUNT(*) FROM products p WHERE {$whereSql}", $params);
$pager = paginate($total, query_int('page', 1), 25);
$rows = Database::all(
    "SELECT p.id, p.title, p.sku, p.stock, p.low_stock_alert, p.price, p.cost_price, p.image_url, c.name AS category_name
       FROM products p LEFT JOIN categories c ON c.id = p.category_id
      WHERE {$whereSql}
   ORDER BY " . ($filter === '' && $search === '' ? 'p.stock ASC' : 'p.stock ASC') . ", p.title ASC
      LIMIT {$pager['limit']} OFFSET {$pager['offset']}",
    $params
);
$summary = Reports::inventory();
$movements = Inventory::recent(15);
$returnUrl = admin_url('inventory.php', ['filter' => $filter, 'q' => $search]);

$adminTitle = 'Inventory';
$adminSubtitle = number_format($summary['units']) . ' units · cost value ' . money($summary['cost_value']) . ' · retail value ' . money($summary['retail_value']);
$adminActions = '<a class="btn btn-sm btn-brand" href="' . e(admin_url('product-form.php')) . '"><i class="bi bi-plus-lg me-1"></i> New product</a>';
require __DIR__ . '/includes/layout-top.php';
?>
<div class="row g-2 g-md-3 mb-3">
  <?php
  $tiles = [
      ['SKUs tracked', number_format($summary['skus']), 'bi-boxes', admin_url('inventory.php')],
      ['Out of stock', number_format($summary['out_of_stock']), 'bi-exclamation-octagon', admin_url('inventory.php', ['filter' => 'out'])],
      ['Low stock', number_format($summary['low_stock']), 'bi-exclamation-triangle', admin_url('inventory.php', ['filter' => 'low'])],
      ['Stock value (cost)', money($summary['cost_value']), 'bi-cash-stack', admin_url('inventory.php')],
  ];
  foreach ($tiles as [$label, $value, $icon, $href]): ?>
    <div class="col-6 col-lg-3">
      <a class="kpi-card d-block text-decoration-none text-ink-900" href="<?= e($href) ?>">
        <div class="d-flex justify-content-between align-items-start">
          <div class="min-w-0">
            <div class="kpi-label"><?= e($label) ?></div>
            <div class="kpi-value text-truncate"><?= e($value) ?></div>
          </div>
          <span class="kpi-icon"><i class="bi <?= e($icon) ?>"></i></span>
        </div>
      </a>
    </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <form class="card-soft p-2 mb-3 d-flex flex-wrap gap-2 align-items-center" method="get" action="<?= e(admin_url('inventory.php')) ?>" data-live-filter>
      <input class="form-control form-control-sm" style="max-width:260px" name="q" type="search" value="<?= e($search) ?>"
             placeholder="Search title or SKU" data-live-input autocomplete="off">
      <select class="form-select form-select-sm" style="max-width:170px" name="filter">
        <option value="">All stock levels</option>
        <option value="low" <?= $filter === 'low' ? 'selected' : '' ?>>Low stock only</option>
        <option value="out" <?= $filter === 'out' ? 'selected' : '' ?>>Out of stock</option>
      </select>
      <button class="btn btn-sm btn-brand" type="submit">Apply</button>
      <a class="btn btn-sm btn-soft" href="<?= e(admin_url('inventory.php')) ?>">Clear</a>
    </form>

    <div data-live-results>
    <div class="card-soft p-0">
      <div class="table-responsive">
        <table class="table table-admin mb-0">
          <thead><tr><th>Product</th><th class="text-center">In stock</th><th class="text-end">Value</th><th style="width:290px">Adjust</th></tr></thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <img src="<?= e(img_url($row['image_url'], (string) $row['title'], 80)) ?>" alt="" width="36" height="36" style="border-radius:.4rem;object-fit:cover;background:var(--ink-100)">
                    <div class="min-w-0">
                      <a class="text-decoration-none d-block text-truncate" style="max-width:260px" href="<?= e(admin_url('product-form.php', ['id' => (int) $row['id']])) ?>"><?= e($row['title']) ?></a>
                      <div class="text-ink-400" style="font-size:.68rem"><?= e($row['sku']) ?><?= !empty($row['category_name']) ? ' · ' . e($row['category_name']) : '' ?></div>
                    </div>
                  </div>
                </td>
                <td class="text-center">
                  <span class="badge text-bg-<?= (int) $row['stock'] <= 0 ? 'danger' : ((int) $row['stock'] <= (int) $row['low_stock_alert'] ? 'warning' : 'success') ?>"><?= (int) $row['stock'] ?></span>
                  <div class="text-ink-400" style="font-size:.66rem">alert at <?= (int) $row['low_stock_alert'] ?></div>
                </td>
                <td class="text-end small"><?= e(money((float) $row['stock'] * (float) ($row['cost_price'] ?? $row['price']))) ?></td>
                <td>
                  <form method="post" action="/admin/actions.php" class="d-flex gap-1" data-disable-on-submit>
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="stock_adjust">
                    <input type="hidden" name="product_id" value="<?= (int) $row['id'] ?>">
                    <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                    <select class="form-select form-select-sm" name="mode" style="width:74px" aria-label="Adjustment mode">
                      <option value="delta">±</option>
                      <option value="set">Set</option>
                    </select>
                    <input class="form-control form-control-sm" type="number" name="quantity" placeholder="0" style="width:82px" required aria-label="Quantity">
                    <select class="form-select form-select-sm" name="reason" style="width:110px" aria-label="Reason">
                      <option value="restock">Restock</option>
                      <option value="sale">Sale</option>
                      <option value="return">Return</option>
                      <option value="damage">Damage</option>
                      <option value="count">Stock count</option>
                    </select>
                    <button class="btn btn-sm btn-brand" type="submit" aria-label="Save"><i class="bi bi-check2"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?><tr><td colspan="4" class="text-center small text-ink-500 py-4">Nothing to show.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="mt-3"><?= pagination_links($pager, ['filter' => $filter, 'q' => $search]) ?></div>
    </div><!-- /data-live-results -->
  </div>

  <div class="col-lg-4">
    <div class="card-soft p-3">
      <h2 class="h6 fw-bold mb-2">Recent movements</h2>
      <ul class="list-unstyled small mb-0">
        <?php foreach ($movements as $movement): ?>
          <li class="py-2 border-bottom">
            <div class="d-flex justify-content-between gap-2">
              <span class="text-truncate"><?= e(limit_text((string) $movement['product_title'], 30)) ?></span>
              <span class="fw-semibold <?= (int) $movement['change_qty'] >= 0 ? 'text-brand' : 'text-danger' ?>"><?= (int) $movement['change_qty'] > 0 ? '+' : '' ?><?= (int) $movement['change_qty'] ?></span>
            </div>
            <div class="text-ink-400" style="font-size:.68rem"><?= e(humanize((string) $movement['reason'])) ?> · <?= e(time_ago((string) $movement['created_at'])) ?><?= !empty($movement['admin_name']) ? ' · ' . e($movement['admin_name']) : '' ?></div>
          </li>
        <?php endforeach; ?>
        <?php if ($movements === []): ?><li class="text-ink-500">No stock movements yet.</li><?php endif; ?>
      </ul>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>

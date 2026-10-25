<?php
/**
 * Discount codes.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
admin_require('coupons.view');

$status = (string) query('status', '');
$search = trim((string) query('q', ''));
$list = Coupons::adminList(['status' => $status, 'q' => $search, 'page' => query_int('page', 1)]);
$rows = $list['rows'] ?? [];
$pager = $list['pager'] ?? paginate(0, 1, 25);
$editing = query_int('edit') > 0 ? Coupons::find(query_int('edit')) : null;
$categories = Catalog::categoryFlat(true);
$returnUrl = admin_url('coupons.php');
$canManage = Auth::can('coupons.manage');

$adminTitle = 'Coupons';
$adminSubtitle = number_format($pager['total']) . ' discount codes';
require __DIR__ . '/includes/layout-top.php';
?>
<div class="row g-3">
  <div class="col-lg-7">
    <form class="card-soft p-2 mb-3 d-flex flex-wrap gap-2 align-items-center" method="get"
          action="<?= e(admin_url('coupons.php')) ?>" data-live-filter>
      <div class="btn-group btn-group-sm">
        <?php foreach (['' => 'All', 'active' => 'Active', 'expired' => 'Expired'] as $value => $label): ?>
          <a class="btn btn-sm <?= $status === $value ? 'btn-brand' : 'btn-soft' ?>" href="<?= e(admin_url('coupons.php', ['status' => $value])) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="status" value="<?= e($status) ?>">
      <input class="form-control form-control-sm ms-auto" style="max-width:260px" name="q" type="search"
             value="<?= e($search) ?>" placeholder="Search code or description" data-live-input autocomplete="off">
    </form>
    <div data-live-results>
    <div class="card-soft p-0">
      <div class="table-responsive">
        <table class="table table-admin mb-0">
          <thead><tr><th>Code</th><th>Value</th><th class="text-center">Used</th><th>Window</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($rows as $coupon): ?>
              <tr>
                <td>
                  <span class="fw-semibold font-monospace"><?= e($coupon['code']) ?></span>
                  <div class="text-ink-400" style="font-size:.68rem"><?= e(limit_text((string) ($coupon['description'] ?? ''), 36)) ?></div>
                </td>
                <td class="small">
                  <?php
                  $type = (string) ($coupon['type'] ?? 'percent');
                  echo e(match ($type) {
                      'percent' => (int) $coupon['value'] . '% off',
                      'fixed' => money((float) $coupon['value']) . ' off',
                      'free_shipping' => 'Free delivery',
                      default => humanize($type),
                  });
                  ?>
                  <?php if ((float) ($coupon['min_spend'] ?? 0) > 0): ?><div class="text-ink-400" style="font-size:.66rem">min <?= e(money((float) $coupon['min_spend'])) ?></div><?php endif; ?>
                </td>
                <td class="text-center small"><?= (int) ($coupon['used_count'] ?? 0) ?><?= (int) ($coupon['max_uses'] ?? 0) > 0 ? '<span class="text-ink-400">/' . (int) $coupon['max_uses'] . '</span>' : '' ?></td>
                <td class="small text-ink-500">
                  <?= !empty($coupon['starts_at']) ? e(fmt_date((string) $coupon['starts_at'])) : 'Now' ?> →
                  <?= !empty($coupon['expires_at']) ? e(fmt_date((string) $coupon['expires_at'])) : 'Open' ?>
                </td>
                <td class="text-end text-nowrap">
                  <?php if ($canManage): ?>
                    <a class="btn btn-sm btn-soft" href="<?= e(admin_url('coupons.php', ['edit' => (int) $coupon['id']])) ?>"><i class="bi bi-pencil"></i></a>
                    <form method="post" action="/admin/actions.php" class="d-inline">
                      <?= Csrf::field() ?>
                      <input type="hidden" name="action" value="coupon_toggle">
                      <input type="hidden" name="coupon_id" value="<?= (int) $coupon['id'] ?>">
                      <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                      <button class="btn btn-sm btn-soft" type="submit" title="Toggle active"><i class="bi <?= (int) $coupon['is_active'] === 1 ? 'bi-pause' : 'bi-play' ?>"></i></button>
                    </form>
                    <form method="post" action="/admin/actions.php" class="d-inline" data-confirm="Delete coupon <?= e($coupon['code']) ?>?">
                      <?= Csrf::field() ?>
                      <input type="hidden" name="action" value="coupon_delete">
                      <input type="hidden" name="coupon_id" value="<?= (int) $coupon['id'] ?>">
                      <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                      <button class="btn btn-sm btn-soft" type="submit"><i class="bi bi-trash"></i></button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?><tr><td colspan="5" class="text-center small text-ink-500 py-4">No coupons yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="mt-3"><?= pagination_links($pager, ['status' => $status, 'q' => $search]) ?></div>
    </div><!-- /data-live-results -->
  </div>

  <div class="col-lg-5">
    <?php if ($canManage): ?>
      <form class="card-soft p-3" method="post" action="/admin/actions.php">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="coupon_save">
        <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
        <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h2 class="h6 fw-bold mb-0"><?= $editing === null ? 'New coupon' : 'Edit ' . e((string) $editing['code']) ?></h2>
          <?php if ($editing !== null): ?><a class="small" href="<?= e($returnUrl) ?>">Cancel</a><?php endif; ?>
        </div>
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label small" for="cp-code">Code *</label>
            <input class="form-control form-control-sm text-uppercase font-monospace" id="cp-code" name="code" value="<?= e($editing['code'] ?? '') ?>" required maxlength="40">
          </div>
          <div class="col-6">
            <label class="form-label small" for="cp-type">Type</label>
            <select class="form-select form-select-sm" id="cp-type" name="type">
              <?php foreach (['percent' => 'Percent off', 'fixed' => 'Fixed amount', 'free_shipping' => 'Free shipping'] as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= ($editing['type'] ?? 'percent') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label small" for="cp-description">Description</label>
            <input class="form-control form-control-sm" id="cp-description" name="description" value="<?= e($editing['description'] ?? '') ?>" maxlength="190" placeholder="Shown to shoppers in the deals strip">
          </div>
          <div class="col-6">
            <label class="form-label small" for="cp-value">Value</label>
            <input class="form-control form-control-sm" id="cp-value" type="number" step="0.01" min="0" name="value" value="<?= e((string) ($editing['value'] ?? 0)) ?>">
          </div>
          <div class="col-6">
            <label class="form-label small" for="cp-min">Minimum spend</label>
            <input class="form-control form-control-sm" id="cp-min" type="number" step="0.01" min="0" name="min_spend" value="<?= e((string) ($editing['min_spend'] ?? 0)) ?>">
          </div>
          <div class="col-6">
            <label class="form-label small" for="cp-maxdisc">Max discount</label>
            <input class="form-control form-control-sm" id="cp-maxdisc" type="number" step="0.01" min="0" name="max_discount" value="<?= e((string) ($editing['max_discount'] ?? '')) ?>">
          </div>
          <div class="col-6">
            <label class="form-label small" for="cp-category">Category</label>
            <select class="form-select form-select-sm" id="cp-category" name="applies_to_category_id">
              <option value="">All products</option>
              <?php foreach ($categories as $category): ?>
                <option value="<?= (int) $category['id'] ?>" <?= (int) ($editing['applies_to_category_id'] ?? 0) === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label small" for="cp-maxuses">Total uses</label>
            <input class="form-control form-control-sm" id="cp-maxuses" type="number" min="0" name="max_uses" value="<?= e((string) ($editing['max_uses'] ?? 0)) ?>">
            <div class="form-text">0 = unlimited</div>
          </div>
          <div class="col-6">
            <label class="form-label small" for="cp-peruser">Per customer</label>
            <input class="form-control form-control-sm" id="cp-peruser" type="number" min="0" name="per_user_limit" value="<?= e((string) ($editing['per_user_limit'] ?? 1)) ?>">
          </div>
          <div class="col-6">
            <label class="form-label small" for="cp-start">Starts</label>
            <input class="form-control form-control-sm" id="cp-start" type="date" name="starts_at" value="<?= e(!empty($editing['starts_at']) ? substr((string) $editing['starts_at'], 0, 10) : '') ?>">
          </div>
          <div class="col-6">
            <label class="form-label small" for="cp-end">Expires</label>
            <input class="form-control form-control-sm" id="cp-end" type="date" name="expires_at" value="<?= e(!empty($editing['expires_at']) ? substr((string) $editing['expires_at'], 0, 10) : '') ?>">
          </div>
        </div>
        <div class="form-check form-switch mt-2 mb-3">
          <input class="form-check-input" type="checkbox" id="cp-active" name="is_active" value="1" <?= (int) ($editing['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
          <label class="form-check-label small" for="cp-active">Active</label>
        </div>
        <button class="btn btn-brand btn-sm w-100" type="submit"><?= $editing === null ? 'Create coupon' : 'Save changes' ?></button>
      </form>
    <?php else: ?>
      <div class="card-soft p-3 small text-ink-500">Your role can view coupons but not change them.</div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>

<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
Auth::requireLogin();

$addresses = Addresses::forUser((int) Auth::id());
$editing = null;
$editId = query_int('edit');
if ($editId > 0) {
    $editing = Addresses::find($editId, (int) Auth::id());
}
$accountActive = 'addresses';

$pageTitle = 'My addresses';
$noindex = true;
require INCLUDES_PATH . '/layout/header.php';
?>
<section class="section">
  <div class="container-page">
    <h1 class="h4 fw-bold mb-3">My addresses</h1>
    <div class="row g-4">
      <div class="col-lg-3"><?php require INCLUDES_PATH . '/account-nav.php'; ?></div>
      <div class="col-lg-9">
        <div class="row g-4">
          <div class="col-xl-7">
            <?php if ($addresses === []): ?>
              <?= View::emptyState('bi-geo-alt', 'No addresses yet', 'Save an address to check out in one tap.', null, null) ?>
            <?php endif; ?>
            <div class="row g-2">
              <?php foreach ($addresses as $address): ?>
                <div class="col-md-6">
                  <div class="card-soft p-3 h-100">
                    <div class="d-flex justify-content-between align-items-start">
                      <div>
                        <span class="badge text-bg-light"><?= e($address['label']) ?></span>
                        <?php if ((int) $address['is_default'] === 1): ?><span class="badge text-bg-success">Default</span><?php endif; ?>
                      </div>
                      <a class="small text-decoration-none" href="/account/addresses.php?edit=<?= (int) $address['id'] ?>" aria-label="Edit address"><i class="bi bi-pencil"></i></a>
                    </div>
                    <address class="small mt-2 mb-2">
                      <strong><?= e($address['full_name']) ?></strong><br>
                      <?= e($address['line1']) ?><?= $address['line2'] ? ', ' . e($address['line2']) : '' ?><br>
                      <?= e($address['city']) ?>, <?= e($address['district']) ?> <?= e($address['postal_code']) ?><br>
                      <?= e(phone_display((string) $address['phone'])) ?>
                    </address>
                    <div class="d-flex gap-2">
                      <?php if ((int) $address['is_default'] !== 1): ?>
                        <form method="post" action="/actions/account.php">
                          <?= Csrf::field() ?><input type="hidden" name="action" value="address_default"><input type="hidden" name="id" value="<?= (int) $address['id'] ?>">
                          <button class="btn btn-soft btn-sm" type="submit">Make default</button>
                        </form>
                      <?php endif; ?>
                      <form method="post" action="/actions/account.php" data-confirm="Delete this address?">
                        <?= Csrf::field() ?><input type="hidden" name="action" value="address_delete"><input type="hidden" name="id" value="<?= (int) $address['id'] ?>">
                        <button class="btn btn-soft btn-sm text-danger" type="submit" aria-label="Delete address"><i class="bi bi-trash"></i></button>
                      </form>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="col-xl-5">
            <div class="card-soft p-3 p-md-4">
              <h2 class="h6 fw-bold mb-3"><?= $editing !== null ? 'Edit address' : 'Add a new address' ?></h2>
              <form method="post" action="/actions/account.php" class="row g-2" data-disable-on-submit>
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="<?= $editing !== null ? 'address_update' : 'address_create' ?>">
                <?php if ($editing !== null): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
                <div class="col-6">
                  <label class="form-label" for="label">Label</label>
                  <input class="form-control form-control-sm" id="label" name="label" maxlength="40" value="<?= e($editing['label'] ?? 'Home') ?>">
                </div>
                <div class="col-6">
                  <label class="form-label" for="full_name">Full name</label>
                  <input class="form-control form-control-sm" id="full_name" name="full_name" required maxlength="120" value="<?= e($editing['full_name'] ?? Auth::user()['name']) ?>">
                </div>
                <div class="col-12">
                  <label class="form-label" for="phone">Phone</label>
                  <input class="form-control form-control-sm" id="phone" name="phone" required maxlength="20" value="<?= e($editing['phone'] ?? Auth::user()['phone']) ?>">
                </div>
                <div class="col-12">
                  <label class="form-label" for="line1">Address line 1</label>
                  <input class="form-control form-control-sm" id="line1" name="line1" required maxlength="180" value="<?= e($editing['line1'] ?? '') ?>">
                </div>
                <div class="col-12">
                  <label class="form-label" for="line2">Address line 2</label>
                  <input class="form-control form-control-sm" id="line2" name="line2" maxlength="180" value="<?= e($editing['line2'] ?? '') ?>">
                </div>
                <div class="col-6">
                  <label class="form-label" for="city">City</label>
                  <input class="form-control form-control-sm" id="city" name="city" required maxlength="80" value="<?= e($editing['city'] ?? '') ?>">
                </div>
                <div class="col-6">
                  <label class="form-label" for="district">District</label>
                  <input class="form-control form-control-sm" id="district" name="district" required maxlength="80" value="<?= e($editing['district'] ?? '') ?>">
                </div>
                <div class="col-6">
                  <label class="form-label" for="postal_code">Postal code</label>
                  <input class="form-control form-control-sm" id="postal_code" name="postal_code" maxlength="20" value="<?= e($editing['postal_code'] ?? '') ?>">
                </div>
                <div class="col-6 d-flex align-items-end">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="is_default" name="is_default" value="1" <?= (int) ($editing['is_default'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="is_default">Default address</label>
                  </div>
                </div>
                <div class="col-12 d-flex gap-2">
                  <button class="btn btn-brand btn-sm" type="submit"><?= $editing !== null ? 'Save changes' : 'Add address' ?></button>
                  <?php if ($editing !== null): ?><a class="btn btn-soft btn-sm" href="/account/addresses.php">Cancel</a><?php endif; ?>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

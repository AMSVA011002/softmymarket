<?php
/**
 * The signed-in team member's own profile and password.
 */
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$user = Auth::user();
$returnUrl = admin_url('account.php');

$adminTitle = 'My account';
$adminSubtitle = role_label((string) $user['role']) . ' · ' . $user['email'];
require __DIR__ . '/includes/layout-top.php';
?>
<div class="row g-3">
  <div class="col-lg-6">
    <form class="card-soft p-3" method="post" action="/actions/account.php">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="profile">
      <h2 class="h6 fw-bold mb-2">Profile</h2>
      <label class="form-label small" for="a-name">Name</label>
      <input class="form-control form-control-sm mb-2" id="a-name" name="name" value="<?= e((string) $user['name']) ?>" required maxlength="120">
      <label class="form-label small" for="a-phone">Mobile</label>
      <input class="form-control form-control-sm mb-2" id="a-phone" name="phone" value="<?= e((string) ($user['phone'] ?? '')) ?>" maxlength="20" inputmode="tel">
      <label class="form-label small" for="a-email">Email</label>
      <input class="form-control form-control-sm mb-3" id="a-email" value="<?= e((string) $user['email']) ?>" disabled>
      <button class="btn btn-brand btn-sm" type="submit">Save profile</button>
    </form>
  </div>

  <div class="col-lg-6">
    <form class="card-soft p-3" method="post" action="/actions/account.php">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="password">
      <h2 class="h6 fw-bold mb-2">Change password</h2>
      <label class="form-label small" for="a-current">Current password</label>
      <input class="form-control form-control-sm mb-2" id="a-current" type="password" name="current_password" autocomplete="current-password" required>
      <div class="d-flex justify-content-between align-items-center">
        <label class="form-label small" for="a-new">New password</label>
        <button class="btn btn-link btn-sm p-0 small" type="button" data-toggle-password="a-new">Show</button>
      </div>
      <input class="form-control form-control-sm mb-2" id="a-new" type="password" name="new_password" autocomplete="new-password" minlength="8" required>
      <label class="form-label small" for="a-confirm">Confirm new password</label>
      <input class="form-control form-control-sm mb-3" id="a-confirm" type="password" name="new_password_confirm" autocomplete="new-password" minlength="8" required>
      <button class="btn btn-ocean btn-sm" type="submit">Update password</button>
    </form>
  </div>

  <div class="col-12">
    <div class="card-soft p-3 small text-ink-600">
      <h2 class="h6 fw-bold mb-2">Session</h2>
      <p class="mb-2">Signed in as <strong><?= e((string) $user['name']) ?></strong> (<?= e(role_label((string) $user['role'])) ?>). Your access is limited to what this role allows; every change you make is written to the audit log.</p>
      <a class="btn btn-sm btn-soft" href="<?= e($returnUrl) ?>"><i class="bi bi-arrow-clockwise me-1"></i> Reload</a>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>

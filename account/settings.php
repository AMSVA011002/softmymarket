<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
Auth::requireLogin();

$user = Auth::user();
$accountActive = 'settings';

$pageTitle = 'Account settings';
$noindex = true;
require INCLUDES_PATH . '/layout/header.php';
?>
<section class="section">
  <div class="container-page">
    <h1 class="h4 fw-bold mb-3">Account settings</h1>
    <div class="row g-4">
      <div class="col-lg-3"><?php require INCLUDES_PATH . '/account-nav.php'; ?></div>
      <div class="col-lg-9">
        <div class="row g-4">
          <div class="col-xl-7">
            <div class="card-soft p-3 p-md-4 mb-3">
              <h2 class="h6 fw-bold mb-3">Profile</h2>
              <form method="post" action="/actions/account.php" class="row g-2" data-disable-on-submit>
                <?= Csrf::field() ?><input type="hidden" name="action" value="profile">
                <div class="col-12">
                  <label class="form-label" for="name">Full name</label>
                  <input class="form-control" id="name" name="name" required maxlength="120" value="<?= e($user['name']) ?>">
                </div>
                <div class="col-12">
                  <label class="form-label" for="email">Email</label>
                  <input class="form-control" id="email" type="email" value="<?= e($user['email']) ?>" readonly>
                  <div class="form-text">Contact support to change the email on your account.</div>
                </div>
                <div class="col-12">
                  <label class="form-label" for="phone">Mobile</label>
                  <input class="form-control" id="phone" name="phone" maxlength="20" value="<?= e($user['phone']) ?>">
                </div>
                <div class="col-12">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="marketing" name="marketing" value="1" <?= (int) $user['marketing_opt_in'] === 1 ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="marketing">Email me deals and new arrivals</label>
                  </div>
                </div>
                <div class="col-12"><button class="btn btn-brand" type="submit">Save profile</button></div>
              </form>
            </div>

            <div class="card-soft p-3 p-md-4">
              <h2 class="h6 fw-bold mb-3">Password</h2>
              <form method="post" action="/actions/account.php" class="row g-2" data-disable-on-submit>
                <?= Csrf::field() ?><input type="hidden" name="action" value="password">
                <div class="col-12">
                  <label class="form-label" for="current_password">Current password</label>
                  <input class="form-control" id="current_password" type="password" name="current_password" required autocomplete="current-password">
                </div>
                <div class="col-12">
                  <label class="form-label" for="new_password">New password</label>
                  <input class="form-control" id="new_password" type="password" name="new_password" required minlength="8" autocomplete="new-password">
                </div>
                <div class="col-12">
                  <label class="form-label" for="new_password_confirm">Confirm new password</label>
                  <input class="form-control" id="new_password_confirm" type="password" name="new_password_confirm" required minlength="8" autocomplete="new-password">
                </div>
                <div class="col-12"><button class="btn btn-brand" type="submit">Update password</button></div>
              </form>
            </div>
          </div>

          <div class="col-xl-5">
            <div class="card-soft p-3 mb-3">
              <h2 class="h6 fw-bold mb-2">Account details</h2>
              <div class="summary-row"><span>Account type</span><span><?= e(role_label((string) $user['role'])) ?></span></div>
              <div class="summary-row"><span>Status</span><span class="badge text-bg-success"><?= e(ucfirst((string) $user['status'])) ?></span></div>
              <div class="summary-row"><span>Member since</span><span><?= e(fmt_date((string) $user['created_at'])) ?></span></div>
              <div class="summary-row"><span>Last sign-in</span><span><?= e(fmt_datetime($user['last_login_at'])) ?></span></div>
            </div>

            <div class="card-soft p-3 mb-3">
              <h2 class="h6 fw-bold mb-2">Security</h2>
              <p class="small text-ink-500">Sessions expire after a period of inactivity. Signing out here clears the remember-me cookie on this device.</p>
              <form method="post" action="/logout.php">
                <?= Csrf::field() ?>
                <button class="btn btn-soft btn-sm text-danger" type="submit"><i class="bi bi-box-arrow-right me-1"></i> Sign out of this device</button>
              </form>
            </div>

            <div class="card-soft p-3">
              <h2 class="h6 fw-bold mb-2">Data &amp; privacy</h2>
              <p class="small text-ink-500">You can request a copy of your data or ask us to close your account at any time.</p>
              <a class="btn btn-outline-brand btn-sm" href="/contact.php?topic=privacy"><i class="bi bi-envelope me-1"></i> Contact privacy team</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

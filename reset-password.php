<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$token = (string) query('token', '');
$email = strtolower((string) query('email', ''));
$valid = false;
if ($token !== '' && $email !== '') {
    $valid = Database::exists(
        'SELECT 1 FROM password_resets pr JOIN users u ON u.id = pr.user_id
          WHERE pr.token_hash = :hash AND u.email = :email AND pr.used_at IS NULL AND pr.expires_at > :now LIMIT 1',
        ['hash' => hash('sha256', $token), 'email' => $email, 'now' => date('Y-m-d H:i:s')]
    );
}

$pageTitle = 'Set a new password';
$noindex = true;
$pageClass = 'auth-page';
require INCLUDES_PATH . '/layout/header.php';
?>
<div class="auth-wrap">
  <div class="auth-card">
    <?php if (!$valid): ?>
      <h1 class="h5 fw-bold mb-1">This link has expired</h1>
      <p class="small text-ink-500 mb-3">Reset links are single-use and expire after an hour. Request a new one to continue.</p>
      <a class="btn btn-brand w-100" href="/forgot-password.php">Request a new link</a>
    <?php else: ?>
      <h1 class="h5 fw-bold mb-1">Choose a new password</h1>
      <p class="small text-ink-500 mb-3">For <?= e($email) ?></p>
      <form method="post" action="/actions/auth.php" class="row g-2" data-disable-on-submit>
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="reset">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <input type="hidden" name="email" value="<?= e($email) ?>">
        <div class="col-12">
          <label class="form-label" for="password">New password</label>
          <input class="form-control" id="password" type="password" name="password" required minlength="8" autocomplete="new-password" autofocus>
          <div class="form-text">At least 8 characters with letters and numbers.</div>
        </div>
        <div class="col-12">
          <label class="form-label" for="password_confirm">Confirm new password</label>
          <input class="form-control" id="password_confirm" type="password" name="password_confirm" required minlength="8" autocomplete="new-password">
        </div>
        <div class="col-12 d-grid">
          <button class="btn btn-brand" type="submit">Update password</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

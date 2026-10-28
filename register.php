<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (Auth::check()) {
    redirect('/account/index.php');
}
$next = (string) query('next', '/');
if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//')) {
    $next = '/';
}

$pageTitle = 'Create an account';
$pageDescription = 'Create a SoftMyMarket account to track orders, save addresses and check out faster.';
$noindex = true;
$pageClass = 'auth-page';
require INCLUDES_PATH . '/layout/header.php';
?>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="text-center mb-3">
      <h1 class="h5 fw-bold mb-1">Create your account</h1>
      <p class="small text-ink-500 mb-0">It takes less than a minute — and checkout gets faster.</p>
      <p class="small text-brand mt-2 mb-0"><i class="bi bi-envelope-check me-1"></i> You’ll need to confirm your email before you can sign in.</p>
    </div>

    <form method="post" action="/actions/auth.php" class="row g-2" data-disable-on-submit>
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="register">
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <div class="col-12">
        <label class="form-label" for="name">Full name</label>
        <input class="form-control" id="name" name="name" required maxlength="120" autocomplete="name" value="<?= e(old('name', '')) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label" for="email">Email</label>
        <input class="form-control" id="email" type="email" name="email" required maxlength="160" autocomplete="email" value="<?= e(old('email', '')) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label" for="phone">Mobile</label>
        <input class="form-control" id="phone" name="phone" required maxlength="20" inputmode="tel" autocomplete="tel" placeholder="077 123 4567" value="<?= e(old('phone', '')) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label" for="password">Password</label>
        <div class="position-relative">
          <input class="form-control" id="password" type="password" name="password" required minlength="8" autocomplete="new-password">
          <button class="btn btn-sm btn-link position-absolute end-0 top-50 translate-middle-y text-decoration-none" type="button" data-toggle-password="password" aria-label="Show password"><i class="bi bi-eye"></i></button>
        </div>
        <div class="form-text">At least 8 characters with letters and numbers.</div>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="password_confirm">Confirm password</label>
        <input class="form-control" id="password_confirm" type="password" name="password_confirm" required minlength="8" autocomplete="new-password">
      </div>
      <div class="col-12">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="terms" name="terms" value="1" required>
          <label class="form-check-label small" for="terms">I agree to the <a href="/terms.php" target="_blank" rel="noopener">terms</a> and <a href="/privacy.php" target="_blank" rel="noopener">privacy policy</a>.</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="marketing" name="marketing" value="1">
          <label class="form-check-label small" for="marketing">Email me deals and new arrivals</label>
        </div>
      </div>
      <div class="col-12 d-grid">
        <button class="btn btn-brand" type="submit">Create account <i class="bi bi-arrow-right"></i></button>
      </div>
    </form>

    <p class="text-center small text-ink-500 mt-3 mb-0">Already have an account? <a class="fw-semibold text-decoration-none" href="/login.php">Sign in</a></p>
  </div>
</div>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (Auth::check()) {
    redirect(Auth::isTeam() ? '/admin/index.php' : '/account/index.php');
}

$next = (string) query('next', '/');
if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//')) {
    $next = '/';
}

$pageTitle = 'Sign in';
$pageDescription = 'Sign in to track orders, save addresses and check out faster.';
$noindex = true;
$pageClass = 'auth-page';
require INCLUDES_PATH . '/layout/header.php';
?>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="text-center mb-3">
      <span class="logo-mark mx-auto mb-2"><?= e(Settings::brand()['initials']) ?></span>
      <h1 class="h5 fw-bold mb-1">Welcome back</h1>
      <p class="small text-ink-500 mb-0">Sign in to your <?= e(Settings::get('store_name')) ?> account</p>
    </div>

    <form method="post" action="/actions/auth.php" class="row g-2" data-disable-on-submit>
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="login">
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <div class="col-12">
        <label class="form-label" for="email">Email address</label>
        <input class="form-control" id="email" type="email" name="email" required autocomplete="email" autofocus value="<?= e(old('email', '')) ?>">
      </div>
      <div class="col-12">
        <div class="d-flex justify-content-between">
          <label class="form-label" for="password">Password</label>
          <a class="small text-decoration-none" href="/forgot-password.php">Forgot?</a>
        </div>
        <div class="position-relative">
          <input class="form-control" id="password" type="password" name="password" required autocomplete="current-password">
          <button class="btn btn-sm btn-link position-absolute end-0 top-50 translate-middle-y text-decoration-none" type="button" data-toggle-password="password" aria-label="Show password"><i class="bi bi-eye"></i></button>
        </div>
      </div>
      <div class="col-12">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1">
          <label class="form-check-label small" for="remember">Keep me signed in for 30 days</label>
        </div>
      </div>
      <div class="col-12 d-grid">
        <button class="btn btn-brand" type="submit">Sign in <i class="bi bi-arrow-right"></i></button>
      </div>
    </form>

    <p class="text-center small text-ink-500 mt-3 mb-2">New here? <a class="fw-semibold text-decoration-none" href="/register.php">Create an account</a></p>
    <p class="text-center small mb-2"><a class="text-decoration-none" href="/verify-email.php">Need a new email confirmation link?</a></p>
    <div class="divider-text"><span>or</span></div>
    <a class="btn btn-soft w-100" href="/track.php"><i class="bi bi-truck me-1"></i> Track an order as a guest</a>

    <?php if (Config::get('app.debug') && !empty(Config::get('app.demo_credentials'))): ?>
      <div class="alert alert-secondary small mt-3 mb-0">
        <strong>Demo accounts</strong><br>
        <?php foreach ((array) Config::get('app.demo_credentials') as $label => $credentials): ?>
          <?= e($label) ?>: <code><?= e($credentials['email']) ?></code> / <code><?= e($credentials['password']) ?></code><br>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

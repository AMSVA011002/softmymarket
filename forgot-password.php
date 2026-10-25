<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (Auth::check()) {
    redirect('/account/index.php');
}

$pageTitle = 'Reset your password';
$noindex = true;
$pageClass = 'auth-page';
require INCLUDES_PATH . '/layout/header.php';
?>
<div class="auth-wrap">
  <div class="auth-card">
    <h1 class="h5 fw-bold mb-1">Forgot your password?</h1>
    <p class="small text-ink-500 mb-3">Enter the email on your account and we will send a secure reset link. It expires in one hour.</p>
    <form method="post" action="/actions/auth.php" class="row g-2" data-disable-on-submit>
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="forgot">
      <div class="col-12">
        <label class="form-label" for="email">Email address</label>
        <input class="form-control" id="email" type="email" name="email" required autocomplete="email" autofocus>
      </div>
      <div class="col-12 d-grid">
        <button class="btn btn-brand" type="submit">Send reset link</button>
      </div>
    </form>
    <p class="text-center small text-ink-500 mt-3 mb-0"><a class="text-decoration-none" href="/login.php"><i class="bi bi-arrow-left"></i> Back to sign in</a></p>
  </div>
</div>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

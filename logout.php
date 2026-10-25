<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (!is_post()) {
    // A GET to this URL should not sign anyone out (CSRF-proofing).
    $pageTitle = 'Sign out';
    $noindex = true;
    require INCLUDES_PATH . '/layout/header.php';
    ?>
    <div class="auth-wrap">
      <div class="auth-card text-center">
        <h1 class="h6 fw-bold">Sign out of your account?</h1>
        <p class="small text-ink-500">You will need to sign in again to view orders.</p>
        <form method="post" action="/logout.php" class="d-grid gap-2">
          <?= Csrf::field() ?>
          <button class="btn btn-brand" type="submit">Yes, sign me out</button>
          <a class="btn btn-soft" href="/account/index.php">Stay signed in</a>
        </form>
      </div>
    </div>
    <?php
    require INCLUDES_PATH . '/layout/footer.php';
    exit;
}

Csrf::verifyRequest();
Auth::logout();
Session::start();
flash('info', 'You have been signed out.');
redirect('/');

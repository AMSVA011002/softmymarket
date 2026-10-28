<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$email = strtolower(trim((string) query('email', '')));
$token = (string) query('token', '');
$verified = false;
$error = '';
if ($token !== '' || $email !== '') {
    $result = EmailVerification::confirm($email, $token);
    if ($result['ok']) {
        $_SESSION['_email_verified_continue_user'] = (int) $result['user_id'];
        $_SESSION['_email_verified_continue_at'] = time();
        $verified = true;
    } elseif ($token !== '') {
        $error = (string) ($result['error'] ?? 'That confirmation link is invalid or has expired.');
    }
}

$pageTitle = $verified ? 'Email confirmed' : 'Confirm your email';
$pageDescription = 'Confirm your email address to activate your SoftMyMarket account.';
$noindex = true;
$pageClass = 'auth-page';
require INCLUDES_PATH . '/layout/header.php';
?>
<div class="auth-wrap">
  <div class="auth-card text-center">
    <?php if ($verified): ?>
      <span class="logo-mark mx-auto mb-3"><i class="bi bi-check2"></i></span>
      <h1 class="h5 fw-bold mb-2">Email confirmed successfully</h1>
      <p class="small text-ink-500">Your account is verified and ready. Continue to the store to sign in and start shopping.</p>
      <form method="post" action="/actions/email-verification.php" class="d-grid mt-3">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="continue">
        <button class="btn btn-brand" type="submit">Continue to the store <i class="bi bi-arrow-right"></i></button>
      </form>
    <?php elseif (query('sent') === '1'): ?>
      <span class="logo-mark mx-auto mb-3"><i class="bi bi-envelope-check"></i></span>
      <h1 class="h5 fw-bold mb-2">Check your inbox</h1>
      <p class="small text-ink-500">We sent a confirmation link to <strong><?= e($email) ?></strong>. Open it within 24 hours to verify your account.</p>
      <p class="small text-ink-500 mb-0">Can’t find it? Check your spam folder, then request another link below.</p>
      <?php require __DIR__ . '/includes/partials/email-verification-resend.php'; ?>
    <?php else: ?>
      <span class="logo-mark mx-auto mb-3"><i class="bi bi-envelope-exclamation"></i></span>
      <h1 class="h5 fw-bold mb-2">Email confirmation needed</h1>
      <p class="small text-ink-500"><?= e($error !== '' ? $error : (query('delivery') === 'failed' ? 'Your account was created, but the confirmation email could not be delivered. Please check the store email configuration or try again.' : 'Enter the email address used to register and we’ll send a fresh confirmation link if an account is waiting.')) ?></p>
      <?php require __DIR__ . '/includes/partials/email-verification-resend.php'; ?>
    <?php endif; ?>
    <p class="small text-ink-500 mt-3 mb-0"><a href="/login.php">Back to sign in</a></p>
  </div>
</div>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

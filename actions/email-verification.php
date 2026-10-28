<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (!is_post()) {
    redirect('/verify-email.php');
}
Csrf::verifyRequest();

switch ((string) post('action', '')) {
    case 'resend':
        RateLimiter::guard('forgot');
        $email = strtolower(trim((string) post('email', '')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $user = Users::findByEmail($email);
            if ($user !== null && $user['role'] === 'customer' && $user['status'] === 'pending') {
                EmailVerification::sendToPendingUser((int) $user['id']);
            }
        }
        // Do not disclose whether an address has an account or its status.
        flash('info', 'If an account is waiting for confirmation, a fresh link has been sent. Check your inbox and spam folder.');
        redirect('/verify-email.php?email=' . rawurlencode($email) . '&sent=1');

    case 'continue':
        $userId = (int) ($_SESSION['_email_verified_continue_user'] ?? 0);
        $verifiedAt = (int) ($_SESSION['_email_verified_continue_at'] ?? 0);
        unset($_SESSION['_email_verified_continue_user'], $_SESSION['_email_verified_continue_at']);
        if ($userId < 1 || $verifiedAt < time() - 1800) {
            flash('warning', 'Please reopen your email confirmation link and continue within 30 minutes.');
            redirect('/login.php');
        }
        $user = Users::find($userId);
        if ($user === null || $user['role'] !== 'customer' || $user['status'] !== 'active' || empty($user['email_verified_at'])) {
            flash('danger', 'This account is not ready to sign in. Please contact support.');
            redirect('/login.php');
        }
        Auth::establish($user, false);
        Cart::mergeSessionIntoDatabase();
        Audit::log('auth.email_verification_continue', 'user', $userId);
        flash('success', 'Your account is verified. Welcome to ' . Settings::get('store_name') . '!');
        redirect('/');

    default:
        flash('danger', 'Unknown email verification action.');
        redirect('/verify-email.php');
}

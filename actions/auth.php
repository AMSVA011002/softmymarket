<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!is_post()) {
    redirect('/login.php');
}
Csrf::verifyRequest();

$action = (string) post('action', '');

/** Only allow redirects to local pages. */
$next = (string) post('next', '');
if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//')) {
    $next = '/';
}

switch ($action) {
    /* ------------------------------------------------------------- login */
    case 'login':
        RateLimiter::guard('login');
        $email = (string) post('email', '');
        $password = (string) post('password', '');
        if ($email === '' || $password === '') {
            flash('danger', 'Enter your email and password.');
            redirect('/login.php?next=' . rawurlencode($next));
        }
        $result = Auth::attempt($email, $password, (bool) post('remember'));
        if (!$result['ok']) {
            flash('danger', $result['error'] ?? 'Sign in failed.');
            remember_input(['email' => $email]);
            redirect('/login.php?next=' . rawurlencode($next));
        }
        Cart::mergeSessionIntoDatabase();
        flash('success', 'Welcome back, ' . explode(' ', (string) $result['user']['name'])[0] . '!');
        $role = (string) $result['user']['role'];
        redirect($next === '/' && in_array($role, ['admin', 'staff'], true) ? '/admin/index.php' : $next);

    /* ---------------------------------------------------------- register */
    case 'register':
        RateLimiter::guard('register');
        $name = trim((string) post('name', ''));
        $email = strtolower(trim((string) post('email', '')));
        $phone = (string) post('phone', '');
        $password = (string) post('password', '');
        $confirm = (string) post('password_confirm', '');

        $errors = [];
        if (strlen($name) < 2) {
            $errors[] = 'Please enter your full name.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } elseif (Users::emailExists($email)) {
            $errors[] = 'An account with that email already exists. Try signing in instead.';
        }
        if (!is_valid_phone($phone)) {
            $errors[] = 'Enter a valid Sri Lankan mobile number, e.g. 077 123 4567.';
        }
        $errors = array_merge($errors, Auth::passwordErrors($password));
        if ($password !== $confirm) {
            $errors[] = 'The two passwords do not match.';
        }
        if (!post('terms')) {
            $errors[] = 'Please accept the terms of service.';
        }
        if ($errors !== []) {
            remember_input(['name' => $name, 'email' => $email, 'phone' => $phone]);
            flash('danger', implode(' ', array_slice($errors, 0, 3)));
            redirect('/register.php');
        }

        try {
            $userId = Users::create([
                'name' => $name,
                'email' => $email,
                'phone' => normalize_phone($phone),
                'password_hash' => Auth::hashPassword($password),
                'role' => 'customer',
                'status' => 'pending',
                'marketing_opt_in' => (int) (bool) post('marketing'),
                'email_verified_at' => null,
            ]);
        } catch (Throwable $e) {
            error_log('Customer registration failed: ' . $e->getMessage());
            flash('danger', 'We could not create the account. Check the email address or try again.');
            redirect('/register.php');
        }
        Audit::log('auth.register_pending', 'user', $userId, ['email' => $email]);
        $sent = EmailVerification::sendToPendingUser($userId);
        flash($sent ? 'success' : 'warning', $sent
            ? 'Account created. Check your inbox and click the confirmation link to activate it.'
            : 'Your account was saved but the confirmation email could not be sent. Check the email settings or request a new link below.');
        redirect('/verify-email.php?email=' . rawurlencode($email) . ($sent ? '&sent=1' : '&delivery=failed'));

    /* ------------------------------------------------------------ logout */
    case 'logout':
        Auth::logout();
        Session::start();
        flash('info', 'You have been signed out.');
        redirect('/');

    /* ------------------------------------------------------------ forgot */
    case 'forgot':
        RateLimiter::guard('forgot');
        $email = strtolower(trim((string) post('email', '')));
        $generic = 'If that email is registered, a reset link is on its way. Check your inbox and spam folder.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('warning', 'Please enter a valid email address.');
            redirect('/forgot-password.php');
        }
        $user = Users::findByEmail($email);
        if ($user !== null && $user['status'] === 'active') {
            $token = Security::randomToken(32);
            Database::run('UPDATE password_resets SET used_at = :now WHERE user_id = :uid AND used_at IS NULL', [
                'now' => date('Y-m-d H:i:s'), 'uid' => (int) $user['id'],
            ]);
            Database::insert('password_resets', [
                'user_id' => (int) $user['id'],
                'token_hash' => hash('sha256', $token),
                'expires_at' => date('Y-m-d H:i:s', time() + 3600),
                'ip_address' => Security::clientIp(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $link = url('/reset-password.php', ['token' => $token, 'email' => $email]);
            try {
                Mailer::passwordReset($user, $link);
            } catch (Throwable $e) {
                error_log('Password reset email failed: ' . $e->getMessage());
            }
            Audit::log('auth.password_reset_requested', 'user', (int) $user['id']);
        }
        flash('info', $generic);
        redirect('/login.php');

    /* ------------------------------------------------------------- reset */
    case 'reset':
        RateLimiter::guard('forgot');
        $token = (string) post('token', '');
        $email = strtolower(trim((string) post('email', '')));
        $password = (string) post('password', '');
        $confirm = (string) post('password_confirm', '');

        $row = Database::one(
            'SELECT pr.*, u.email, u.id AS user_id
               FROM password_resets pr
               JOIN users u ON u.id = pr.user_id
              WHERE pr.token_hash = :hash AND u.email = :email AND pr.used_at IS NULL AND pr.expires_at > :now
              ORDER BY pr.id DESC LIMIT 1',
            ['hash' => hash('sha256', $token), 'email' => $email, 'now' => date('Y-m-d H:i:s')]
        );
        if ($row === null) {
            flash('danger', 'That reset link is invalid or has expired. Please request a new one.');
            redirect('/forgot-password.php');
        }
        $errors = Auth::passwordErrors($password);
        if ($password !== $confirm) {
            $errors[] = 'The two passwords do not match.';
        }
        if ($errors !== []) {
            flash('danger', implode(' ', $errors));
            redirect('/reset-password.php?token=' . rawurlencode($token) . '&email=' . rawurlencode($email));
        }
        Database::tx(static function () use ($row, $password): void {
            Users::update((int) $row['user_id'], ['password_hash' => Auth::hashPassword($password)]);
            Database::run('UPDATE password_resets SET used_at = :now WHERE id = :id', [
                'now' => date('Y-m-d H:i:s'), 'id' => (int) $row['id'],
            ]);
            Database::run('UPDATE password_resets SET used_at = :now WHERE user_id = :uid AND used_at IS NULL', [
                'now' => date('Y-m-d H:i:s'), 'uid' => (int) $row['user_id'],
            ]);
        });
        Audit::log('auth.password_changed', 'user', (int) $row['user_id']);
        Notifier::notify((int) $row['user_id'], 'Password changed', 'Your password was changed. If this was not you, contact us immediately.', 'warning');
        flash('success', 'Your password has been updated. Sign in with your new password.');
        redirect('/login.php');

    default:
        flash('danger', 'Unknown request.');
        redirect('/');
}

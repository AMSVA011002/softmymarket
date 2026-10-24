<?php
declare(strict_types=1);

/**
 * Authentication + authorisation.
 *
 * Roles
 *   admin    — full access to the dashboard, people, money and settings
 *   staff    — "Store user": day-to-day catalogue, stock, orders and support
 *   customer — the shopper, access only to their own records
 *
 * The permission matrix below is the single source of truth; pages call
 * Auth::can('orders.manage') instead of comparing raw role strings.
 */
final class Auth
{
    private const SESSION_KEY = '_auth_user_id';
    private const REMEMBER_COOKIE = 'smm_remember';

    private static ?array $user = null;
    private static bool $resolved = false;

    /** @var array<string,list<string>> */
    private const PERMISSIONS = [
        'admin' => ['*'],
        'staff' => [
            'dashboard.view',
            'products.manage',
            'inventory.manage',
            'orders.view',
            'orders.manage',
            'customers.view',
            'customers.manage',
            'reviews.manage',
            'payments.verify',
            'messages.view',
            'liveboard.view',
            'coupons.view',
            'coupons.manage',
            'analytics.view',
            'settings.view',
        ],
        'customer' => ['account.self'],
    ];

    public static function boot(): void
    {
        if (self::$resolved) {
            return;
        }
        self::$resolved = true;

        $id = $_SESSION[self::SESSION_KEY] ?? null;
        if ($id !== null) {
            $user = Users::find((int) $id);
            if ($user !== null && $user['status'] === 'active') {
                self::$user = $user;
                return;
            }
            unset($_SESSION[self::SESSION_KEY]);
        }

        // Remember-me cookie: restores the session for returning shoppers.
        if (!empty($_COOKIE[self::REMEMBER_COOKIE])) {
            self::loginFromRememberCookie((string) $_COOKIE[self::REMEMBER_COOKIE]);
        }
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        self::boot();
        return self::$user;
    }

    public static function id(): ?int
    {
        $user = self::user();
        return $user !== null ? (int) $user['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function guest(): bool
    {
        return !self::check();
    }

    public static function role(): ?string
    {
        return self::user()['role'] ?? null;
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    public static function isStaff(): bool
    {
        return self::role() === 'staff';
    }

    public static function isCustomer(): bool
    {
        return self::role() === 'customer';
    }

    /** Staff or admin — anyone allowed into the dashboard shell. */
    public static function isTeam(): bool
    {
        return in_array(self::role(), ['admin', 'staff'], true);
    }

    public static function can(string $permission): bool
    {
        $role = self::role();
        if ($role === null) {
            return false;
        }
        $granted = self::PERMISSIONS[$role] ?? [];
        return in_array('*', $granted, true) || in_array($permission, $granted, true);
    }

    /** Send guests to the sign-in page (keeping the intended destination). */
    public static function requireLogin(): void
    {
        if (self::check()) {
            return;
        }
        if (wants_json()) {
            json_response(['error' => 'Please sign in to continue.', 'login' => '/login.php'], 401);
        }
        $next = $_SERVER['REQUEST_URI'] ?? '/';
        redirect('/login.php?next=' . rawurlencode($next));
    }

    /** Require one of the given roles. */
    public static function requireRole(string ...$roles): void
    {
        self::requireLogin();
        if (!in_array(self::role(), $roles, true)) {
            abort(403, 'Your account does not have access to this area.');
        }
    }

    public static function requirePermission(string $permission): void
    {
        self::requireLogin();
        if (!self::can($permission)) {
            abort(403, 'You do not have permission to perform this action.');
        }
    }

    /**
     * Attempt a sign-in.
     *
     * @return array{ok:bool,user?:array<string,mixed>,error?:string,lockout?:int}
     */
    public static function attempt(string $email, string $password, bool $remember = false): array
    {
        $email = strtolower(trim($email));
        $ip = Security::clientIp();

        $lock = RateLimiter::check('login', $email . '|' . $ip);
        if ($lock['blocked']) {
            return ['ok' => false, 'error' => 'Too many attempts. Try again in ' . ceil($lock['retry_after'] / 60) . ' minute(s).', 'lockout' => $lock['retry_after']];
        }

        $user = Users::findByEmail($email);
        $hash = $user['password_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidin';
        $valid = password_verify($password, $hash);

        if ($user === null || !$valid) {
            RateLimiter::hit('login', $email . '|' . $ip, (int) Config::get('security.max_login_attempts', 5), (int) Config::get('security.lockout_minutes', 15) * 60);
            Audit::log('auth.login_failed', 'user', null, ['email' => $email]);
            return ['ok' => false, 'error' => 'Email or password is incorrect.'];
        }

        if ($user['status'] !== 'active') {
            return ['ok' => false, 'error' => 'This account is suspended. Contact support for help.'];
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            Users::update((int) $user['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }

        RateLimiter::clear('login', $email . '|' . $ip);
        self::establish($user, $remember);
        Users::update((int) $user['id'], ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => $ip]);
        Audit::log('auth.login', 'user', (int) $user['id']);

        return ['ok' => true, 'user' => $user];
    }

    /** Log a user in and refresh the session id (prevents session fixation). */
    public static function establish(array $user, bool $remember = false): void
    {
        Session::regenerate();
        $_SESSION[self::SESSION_KEY] = (int) $user['id'];
        self::$user = $user;
        Csrf::rotate();

        if ($remember) {
            $expires = time() + (int) Config::get('session.lifetime', 1209600);
            $token = self::rememberToken((int) $user['id'], $expires, (string) $user['password_hash']);
            setcookie(self::REMEMBER_COOKIE, $token, [
                'expires'  => $expires,
                'path'     => '/',
                'secure'   => Security::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    public static function logout(): void
    {
        $id = self::id();
        if ($id !== null) {
            Audit::log('auth.logout', 'user', $id);
        }
        unset($_SESSION[self::SESSION_KEY]);
        self::$user = null;
        self::$resolved = true;
        if (isset($_COOKIE[self::REMEMBER_COOKIE])) {
            setcookie(self::REMEMBER_COOKIE, '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'secure'   => Security::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        Session::destroy();
    }

    /* --------------------------------------------------------------- tokens */

    private static function rememberToken(int $userId, int $expires, string $passwordHash): string
    {
        $payload = $userId . ':' . $expires . ':' . substr(hash('sha256', $passwordHash), 0, 16);
        $mac = Security::hmac($payload, (string) Config::get('app.key', 'smm'));
        return rtrim(strtr(base64_encode($payload . ':' . $mac), '+/', '-_'), '=');
    }

    private static function loginFromRememberCookie(string $cookie): void
    {
        $decoded = base64_decode(strtr($cookie, '-_', '+/'), true);
        if ($decoded === false) {
            return;
        }
        $parts = explode(':', $decoded);
        if (count($parts) !== 4) {
            return;
        }
        [$userId, $expires, $fingerprint, $mac] = $parts;
        if ((int) $expires < time()) {
            return;
        }
        $payload = $userId . ':' . $expires . ':' . $fingerprint;
        if (!hash_equals(Security::hmac($payload, (string) Config::get('app.key', 'smm')), $mac)) {
            return;
        }
        $user = Users::find((int) $userId);
        if ($user === null || $user['status'] !== 'active') {
            return;
        }
        if (!hash_equals(substr(hash('sha256', (string) $user['password_hash']), 0, 16), $fingerprint)) {
            return; // password changed since the cookie was issued
        }
        $_SESSION[self::SESSION_KEY] = (int) $user['id'];
        self::$user = $user;
    }

    /* ------------------------------------------------------------ passwords */

    /** @return list<string> validation errors */
    public static function passwordErrors(string $password): array
    {
        $errors = [];
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must include letters and numbers.';
        }
        return $errors;
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
    }

    /** Re-usable array for templates. */
    public static function current(): ?array
    {
        $user = self::user();
        if ($user === null) {
            return null;
        }
        return [
            'id'    => (int) $user['id'],
            'name'  => $user['name'],
            'email' => $user['email'],
            'role'  => $user['role'],
            'avatar_url' => $user['avatar_url'] ?? null,
        ];
    }
}

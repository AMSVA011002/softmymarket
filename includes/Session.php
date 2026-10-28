<?php
declare(strict_types=1);

/**
 * Hardened session wrapper.
 *
 * - httpOnly + SameSite cookies, secure flag when HTTPS is detected
 * - strict mode (attacker supplied ids are rejected)
 * - idle timeout and periodic id rotation
 * - one-shot flash messages
 */
final class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        $secure = (bool) Config::get('session.secure', true);
        if ($secure && !Security::isHttps()) {
            $secure = false; // allow plain-HTTP staging without breaking logins
        }

        $name = (string) Config::get('session.name', 'yourmarket_session');
        $options = [
            'name'            => $name,
            'cookie_lifetime' => 0,
            'cookie_path'     => '/',
            'cookie_domain'   => (string) Config::get('session.domain', ''),
            'cookie_secure'   => $secure,
            'cookie_httponly' => true,
            'cookie_samesite' => (string) Config::get('session.samesite', 'Lax'),
        ];

        // PHP refuses to change the session name (and cannot send cookies) once
        // headers are on their way. That happens on shared hosting when the host
        // prints a warning or a stray byte before us — previously an uncaught
        // ErrorException that took the whole page down with a generic 500.
        // Degrading to a guest request keeps the storefront usable instead.
        if (headers_sent()) {
            error_log('[smm] output already started; continuing without a PHP session');
            self::$started = true;
            Csrf::boot();
            return;
        }

        session_name($name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => (string) Config::get('session.domain', ''),
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => (string) Config::get('session.samesite', 'Lax'),
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        // PHP 8.4 deprecated these two in favour of session.sid_length defaults;
        // setting them there raises E_DEPRECATED on every request.
        if (PHP_VERSION_ID < 80400) {
            ini_set('session.sid_length', '48');
            ini_set('session.sid_bits_per_character', '6');
        }

        if (!@session_start($options)) {
            // Session storage unwritable (a very common free-hosting problem).
            // Carry on as a guest rather than 500-ing every page.
            error_log('[smm] session_start() failed; continuing without a session');
            self::$started = true;
            Csrf::boot();
            return;
        }
        self::$started = true;

        $now = time();
        $created = (int) ($_SESSION['_created'] ?? 0);
        $seen = (int) ($_SESSION['_seen'] ?? 0);
        $idle = (int) Config::get('session.idle', 7200);

        if ($created === 0) {
            $_SESSION['_created'] = $now;
        } elseif ($idle > 0 && $seen > 0 && $now - $seen > $idle) {
            self::destroy();
            session_start();
            $_SESSION['_created'] = $now;
            flash('warning', 'Your session expired for security. Please sign in again.');
        }
        $_SESSION['_seen'] = $now;

        $rotate = (int) Config::get('security.session_regenerate', 900);
        if ($rotate > 0 && $now - $created > $rotate) {
            session_regenerate_id(true);
            $_SESSION['_created'] = $now;
        }

        // Bind the session to a fingerprint so stolen cookies from another
        // browser/user-agent family stop working.
        $fingerprint = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . (string) Config::get('app.key', ''));
        if (!isset($_SESSION['_fp'])) {
            $_SESSION['_fp'] = $fingerprint;
        } elseif (!hash_equals($_SESSION['_fp'], $fingerprint)) {
            self::destroy();
            session_start();
            $_SESSION['_fp'] = $fingerprint;
            $_SESSION['_created'] = $now;
        }

        Csrf::boot();
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['_created'] = time();
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);
        return $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => true,
                'samesite' => $params['samesite'] ?: 'Lax',
            ]);
        }
        session_destroy();
        self::$started = false;
    }
}

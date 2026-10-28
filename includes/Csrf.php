<?php
declare(strict_types=1);

/**
 * CSRF protection.
 *
 * Every state-changing request (all POSTs) must carry a token issued to the
 * current session. A small ring of valid tokens is kept so that multiple open
 * tabs — or a form generated before another form was submitted — keep working,
 * while previously used tokens are retired.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_tokens';
    private const MAX_TOKENS = 12;
    public const FIELD = '_token';

    public static function boot(): void
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }
        if ($_SESSION[self::SESSION_KEY] === []) {
            self::rotate();
        }
    }

    public static function token(): string
    {
        self::boot();
        return (string) end($_SESSION[self::SESSION_KEY]);
    }

    public static function rotate(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION[self::SESSION_KEY][] = $token;
        $_SESSION[self::SESSION_KEY] = array_slice(array_values(array_unique($_SESSION[self::SESSION_KEY])), -self::MAX_TOKENS);
        return $token;
    }

    /** Hidden input for forms. */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function check(?string $token): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }
        self::boot();
        foreach ($_SESSION[self::SESSION_KEY] as $valid) {
            if (hash_equals($valid, $token)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Validate the current request and terminate with 419 when the token is
     * missing or wrong. Safe to call from every POST handler; it is also called
     * automatically for POST requests in helpers.php:enforce_csrf().
     */
    public static function verifyRequest(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }
        $token = $_POST[self::FIELD] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!self::check(is_string($token) ? $token : null)) {
            http_response_code(419);
            if (wants_json()) {
                json_response(['error' => 'Your security token expired. Refresh the page and try again.'], 419);
            }
            $appName = htmlspecialchars(Settings::get('store_name', (string) Config::get('app.name', 'YourMarket')), ENT_QUOTES, 'UTF-8');
            echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Session expired</title>';
            echo '<link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css"></head>';
            echo '<body class="bg-light d-flex align-items-center min-vh-100"><div class="container" style="max-width:520px">';
            echo '<div class="card shadow-sm"><div class="card-body p-4 text-center">';
            echo '<h1 class="h4 fw-bold mb-2">Your session expired</h1>';
            echo '<p class="text-secondary mb-4">For your security the form could not be verified. Please reload the page and submit it again.</p>';
            echo '<a class="btn btn-success" href="">Reload page</a>';
            echo '<a class="btn btn-outline-secondary ms-2" href="/">Go to ' . $appName . '</a>';
            echo '</div></div></div></body></html>';
            exit;
        }
    }
}

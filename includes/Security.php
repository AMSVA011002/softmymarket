<?php
declare(strict_types=1);

/**
 * Request-level security helpers: hardening, headers, client IP resolution and
 * HMAC signing for tokens that travel through untrusted channels (password
 * reset links, public order tracking links, payment callbacks).
 */
final class Security
{
    private const CIPHER = 'aes-256-gcm';

    /**
     * CSP nonce for this request. Inline <script> blocks must carry it.
     */
    public static function nonce(): string
    {
        static $nonce = null;
        if ($nonce === null) {
            $nonce = base64_encode(random_bytes(18));
        }
        return $nonce;
    }

    public static function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(self)');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('X-Permitted-Cross-Domain-Policies: none');

        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        $csp = implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self' https://www.payhere.lk https://sandbox.payhere.lk",
            "frame-ancestors 'self'",
            "object-src 'none'",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data:",
            "style-src 'self' 'unsafe-inline' https:",
            "script-src 'self' 'nonce-" . self::nonce() . "' https://www.googletagmanager.com",
            "connect-src 'self' https://www.google-analytics.com https://region1.google-analytics.com",
            "manifest-src 'self'",
        ]);
        header('Content-Security-Policy: ' . $csp);

        // Never cache personalised HTML.
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
        }
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        // Only believe the proxy header when the request really came from a proxy.
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && self::fromTrustedProxy()) {
            return strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
        }
        return false;
    }

    /**
     * Best-effort scheme for generated absolute URLs (emails, payment callbacks).
     *
     * Looser than isHttps(): shared hosts often terminate TLS at a proxy that
     * only announces itself through X-Forwarded-Proto. Guessing "https" there
     * avoids mixed-content links, while cookies stay on the strict check so a
     * Secure cookie is never issued over plain HTTP.
     */
    public static function scheme(): string
    {
        if (self::isHttps()) {
            return 'https';
        }
        $forwarded = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($forwarded === 'https' || $forwarded === 'wss') {
            return 'https';
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') {
            return 'https';
        }
        if (strtolower((string) ($_SERVER['HTTP_FRONT_END_HTTPS'] ?? '')) === 'on') {
            return 'https';
        }
        return 'http';
    }

    public static function clientIp(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (self::fromTrustedProxy()) {
            $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if ($forwarded !== '') {
                $first = trim(explode(',', (string) $forwarded)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    return $first;
                }
            }
            if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) && filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP)) {
                return (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
            }
        }
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }

    /** True when REMOTE_ADDR matches the configured proxy allow-list. */
    private static function fromTrustedProxy(): bool
    {
        $proxies = (array) Config::get('security.trusted_proxies', []);
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return $remote !== '' && in_array($remote, $proxies, true);
    }

    /** 64 character hex key derived from app.key. */
    private static function key(): string
    {
        $secret = (string) Config::get('app.key', '');
        if ($secret === '' || str_contains($secret, 'CHANGE-ME')) {
            // Never fail open in production: derive a stable per-install key from
            // the database credentials so tokens cannot be forged across hosts.
            $secret = hash('sha256', $secret . '|' . Config::get('db.name', '') . '|' . Config::get('db.user', ''));
        }
        return hash('sha256', $secret, true);
    }

    /** URL-safe base64 token of arbitrary data signed with HMAC-SHA256. */
    public static function sign(array $payload, ?int $expiresIn = null): string
    {
        if ($expiresIn !== null) {
            $payload['exp'] = time() + $expiresIn;
        }
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $body = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        return $body . '.' . self::mac($body);
    }

    /**
     * @return array<string,mixed>|null null when the token is invalid/expired
     */
    public static function verify(string $token): ?array
    {
        if (!str_contains($token, '.')) {
            return null;
        }
        [$body, $mac] = explode('.', $token, 2);
        if (!hash_equals(self::mac($body), $mac)) {
            return null;
        }
        $json = base64_decode(strtr($body, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return null;
        }
        if (isset($payload['exp']) && (int) $payload['exp'] < time()) {
            return null;
        }
        return $payload;
    }

    private static function mac(string $body): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $body, self::key(), true)), '+/', '-_'), '=');
    }

    /** Random URL-safe token for one-time links (stored hashed in the database). */
    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /** Constant-time comparison that tolerates null. */
    public static function equals(?string $a, ?string $b): bool
    {
        return is_string($a) && is_string($b) && hash_equals($a, $b);
    }

    /** HMAC signature used for payment gateway callbacks. */
    public static function hmac(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    /** Encrypt a small secret (gateway reference) with the app key. */
    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed');
        }
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $encoded): ?string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    }
}

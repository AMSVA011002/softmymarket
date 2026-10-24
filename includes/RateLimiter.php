<?php
declare(strict_types=1);

/**
 * Database backed rate limiter.
 *
 * Works across PHP-FPM workers (unlike an in-process counter) and needs no
 * Redis on shared hosting. Buckets expire automatically and old rows are
 * pruned opportunistically.
 */
final class RateLimiter
{
    /** @var array<string,array{max:int,window:int}> */
    private const DEFAULTS = [
        'login'      => ['max' => 5,    'window' => 900],
        'register'   => ['max' => 5,    'window' => 3600],
        'forgot'     => ['max' => 4,    'window' => 3600],
        'coupon'     => ['max' => 20,   'window' => 600],
        'checkout'   => ['max' => 10,   'window' => 600],
        'track'      => ['max' => 20,   'window' => 600],
        'newsletter' => ['max' => 5,    'window' => 3600],
        'contact'    => ['max' => 5,    'window' => 3600],
        'review'     => ['max' => 10,   'window' => 3600],
        'search'     => ['max' => 120,  'window' => 60],
        'api'        => ['max' => 240,  'window' => 60],
        'tracking'   => ['max' => 180,  'window' => 60],
        'payment'    => ['max' => 30,   'window' => 600],
    ];

    /** @return array{blocked:bool,hits:int,max:int,retry_after:int}
     */
    public static function check(string $bucket, string $identifier, ?int $max = null, ?int $window = null): array
    {
        $config = self::config($bucket, $max, $window);
        $row = Database::one(
            'SELECT hits, expires_at FROM rate_limits WHERE bucket = :bucket AND identifier = :identifier AND expires_at > NOW()',
            ['bucket' => $bucket, 'identifier' => self::hash($identifier)]
        );
        if ($row === null) {
            return ['blocked' => false, 'hits' => 0, 'max' => $config['max'], 'retry_after' => 0];
        }
        $retry = max(0, strtotime((string) $row['expires_at']) - time());
        return [
            'blocked' => (int) $row['hits'] >= $config['max'],
            'hits' => (int) $row['hits'],
            'max' => $config['max'],
            'retry_after' => $retry,
        ];
    }

    /** Record one attempt. Returns the new count. */
    public static function hit(string $bucket, string $identifier, ?int $max = null, ?int $window = null): int
    {
        $config = self::config($bucket, $max, $window);
        $identifier = self::hash($identifier);
        $now = date('Y-m-d H:i:s');
        $expires = date('Y-m-d H:i:s', time() + $config['window']);

        Database::run(
            'INSERT INTO rate_limits (bucket, identifier, hits, window_start, expires_at)
             VALUES (:bucket, :identifier, 1, :now, :expires)
             ON DUPLICATE KEY UPDATE
                hits = IF(expires_at <= :now2, 1, hits + 1),
                window_start = IF(expires_at <= :now3, :now4, window_start),
                expires_at = IF(expires_at <= :now5, :expires2, expires_at)',
            [
                'bucket' => $bucket,
                'identifier' => $identifier,
                'now' => $now,
                'expires' => $expires,
                'now2' => $now,
                'now3' => $now,
                'now4' => $now,
                'now5' => $now,
                'expires2' => $expires,
            ]
        );

        $hits = (int) Database::int(
            'SELECT hits FROM rate_limits WHERE bucket = :bucket AND identifier = :identifier',
            ['bucket' => $bucket, 'identifier' => $identifier]
        );

        if (random_int(1, 50) === 1) {
            self::prune();
        }
        return $hits;
    }

    public static function clear(string $bucket, string $identifier): void
    {
        Database::run(
            'DELETE FROM rate_limits WHERE bucket = :bucket AND identifier = :identifier',
            ['bucket' => $bucket, 'identifier' => self::hash($identifier)]
        );
    }

    /**
     * Abort the request with 429 when the bucket is exhausted.
     */
    public static function guard(string $bucket, ?int $max = null, ?int $window = null): void
    {
        $key = Security::clientIp();
        $result = self::check($bucket, $key, $max, $window);
        if ($result['blocked']) {
            header('Retry-After: ' . $result['retry_after']);
            if (wants_json()) {
                json_response(['error' => 'Too many requests. Please slow down.', 'retry_after' => $result['retry_after']], 429);
            }
            abort(429, 'Too many requests from this device. Please wait a few minutes and try again.');
        }
        self::hit($bucket, $key, $max, $window);
    }

    public static function prune(): void
    {
        try {
            Database::run('DELETE FROM rate_limits WHERE expires_at < NOW()');
        } catch (Throwable) {
            // pruning is best effort
        }
    }

    /** @return array{max:int,window:int} */
    private static function config(string $bucket, ?int $max, ?int $window): array
    {
        $defaults = self::DEFAULTS[$bucket] ?? ['max' => 60, 'window' => 60];
        if ($bucket === 'login') {
            $defaults['max'] = (int) Config::get('security.max_login_attempts', $defaults['max']);
            $defaults['window'] = (int) Config::get('security.lockout_minutes', 15) * 60;
        }
        return [
            'max' => $max ?? $defaults['max'],
            'window' => $window ?? $defaults['window'],
        ];
    }

    private static function hash(string $identifier): string
    {
        return substr(hash('sha256', $identifier), 0, 64);
    }
}

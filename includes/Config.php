<?php
declare(strict_types=1);

/**
 * Immutable configuration container with dot-notation access.
 *
 * Config::load() is called once from includes/bootstrap.php. Values can be
 * overridden by environment variables (useful for staging or Docker) using the
 * SMM_* prefix, e.g. SMM_DB_HOST, SMM_DB_NAME, SMM_APP_URL.
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $items = [];
    private static bool $loaded = false;

    public static function load(array $items): void
    {
        self::$items = $items;
        self::$loaded = true;
    }

    public static function loaded(): bool
    {
        return self::$loaded;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $ref = &self::$items;
        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
    }

    public static function all(): array
    {
        return self::$items;
    }

    public static function required(string $key): mixed
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new RuntimeException(sprintf('Missing required configuration value: %s', $key));
        }
        return $value;
    }

    public static function isProduction(): bool
    {
        return self::get('app.env', 'production') === 'production';
    }
}

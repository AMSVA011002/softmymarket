<?php
declare(strict_types=1);

/**
 * Store settings (single row per key) with per-request caching.
 *
 * Defaults live in code so a fresh install works before anything is saved, and
 * so an admin can clear an override without breaking the storefront.
 */
final class Settings
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    /** @var array<string,string> */
    public const DEFAULTS = [
        'store_name'            => 'YourMarket',
        'store_tagline'         => 'Sri Lanka\'s trusted online marketplace',
        'store_description'     => 'Shop quality products from one trusted local store, with reliable delivery and helpful customer support.',
        'store_language'        => 'en-LK',
        'store_locality'        => 'Colombo',
        'store_country'         => 'Sri Lanka',
        'store_country_code'    => 'LK',
        'store_hours'           => '',
        'delivery_promise'      => '1–4 working days',
        'support_response_time' => 'within one business day',
        'social_image_url'      => '',
        'support_email'         => 'hello@yourmarket.example',
        'mail_transport'        => 'smtp',
        'mail_from_email'       => '',
        'mail_from_name'        => '',
        'mail_reply_to'         => '',
        'mail_smtp_host'        => '',
        'mail_smtp_port'        => '465',
        'mail_smtp_encryption'  => 'ssl',
        'mail_smtp_username'    => '',
        'mail_smtp_password_enc' => '',
        'mail_smtp_timeout'     => '15',
        'support_phone'         => '+94 11 234 5678',
        'support_whatsapp'      => '+94 77 123 4567',
        'address'               => 'No. 120, Galle Road, Colombo 03, Sri Lanka',
        'announcement'          => 'Welcome — delivery options and order details are shown at checkout.',
        'currency'              => 'LKR',
        'currency_symbol'       => 'Rs',
        'logo_url'              => '',
        'favicon_url'           => '',
        'brand_color'           => '#0c800f',
        'brand_color_dark'      => '#0a6510',
        'accent_color'          => '#0a57a0',
        'free_shipping_threshold' => '15000',
        'default_shipping_fee'  => '450',
        'tax_percent'           => '0',
        'orders_email'          => 'orders@yourmarket.example',
                'facebook_url'         => '',
        'instagram_url'         => '',
        'whatsapp_url'          => '',
        'tiktok_url'            => '',
        'youtube_url'           => '',
        'twitter_url'           => '',
        'footer_note'           => 'Thank you for shopping with us.',
        'trust_delivery_title'  => 'Island-wide delivery',
        'trust_delivery_text'   => 'Delivery times are shown at checkout',
        'trust_payment_title'   => 'Flexible payments',
        'trust_payment_text'    => 'Available methods appear at checkout',
        'trust_quality_title'   => 'Carefully selected products',
        'trust_quality_text'    => 'Product details shown on each listing',
        'trust_returns_title'   => 'Customer care',
        'trust_returns_text'    => 'Contact our team if you need help',
        'faq_items'             => '',
        'terms_text'            => '',
        'privacy_text'          => '',
        'shipping_text'         => '',
        'returns_text'          => '',
        'about_text'            => '',
        'maintenance_mode'      => '0',
        'allow_reviews'         => '1',
        'allow_guest_checkout'  => '1',
        'live_tracking_refresh' => '15',
        'low_stock_threshold'   => '5',
        'order_prefix'          => 'YM',
        'return_window_days'    => '7',
        'default_meta_title'    => '',
        'default_meta_description' => '',
        'google_analytics_id'   => '',
        'meta_pixel_id'         => '',
        'bank_transfer_note'    => 'Upload your deposit slip or enter the transfer reference after ordering. Orders ship once finance verifies the payment.',
        'tracking_note'         => '',
        'hero_slides'           => '',
    ];

    /** @return array<string,string> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $values = self::DEFAULTS;
        try {
            $rows = Database::all('SELECT setting_key, setting_value FROM settings');
            foreach ($rows as $row) {
                $values[(string) $row['setting_key']] = (string) $row['setting_value'];
            }
        } catch (Throwable $e) {
            // During install (or before the schema exists) fall back to defaults.
            error_log('Settings load failed: ' . $e->getMessage());
        }
        self::$cache = $values;
        return self::$cache;
    }

    public static function get(string $key, ?string $default = null): string
    {
        $all = self::all();
        if (array_key_exists($key, $all) && $all[$key] !== '') {
            return $all[$key];
        }
        return $default ?? (self::DEFAULTS[$key] ?? '');
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return $value === '' ? $default : (int) $value;
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $value = self::get($key);
        return $value === '' ? $default : (float) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === '') {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
    }

    public static function set(string $key, string $value): void
    {
        Database::run(
            'INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (:key, :value, :now)
             ON DUPLICATE KEY UPDATE setting_value = :value2, updated_at = :now2',
            ['key' => $key, 'value' => $value, 'now' => date('Y-m-d H:i:s'), 'value2' => $value, 'now2' => date('Y-m-d H:i:s')]
        );
        self::$cache = null;
    }

    /** @param array<string,string> $values */
    public static function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            self::set($key, $value);
        }
    }

    public static function forget(): void
    {
        self::$cache = null;
    }

    /** Convenience: the storefront brand block used by layouts and emails. */
    public static function brand(): array
    {
        return [
            'name'        => self::get('store_name', (string) Config::get('app.name', 'YourMarket')),
            'initials'    => initials(self::get('store_name', (string) Config::get('app.name', 'YourMarket'))),
            'tagline'     => self::get('store_tagline'),
            'logo'        => self::get('logo_url'),
            'favicon'     => self::get('favicon_url'),
            'color'       => self::get('brand_color', '#0c800f'),
            'color_dark'  => self::get('brand_color_dark', '#0a6510'),
            'accent'      => self::get('accent_color', '#0a57a0'),
            'phone'       => self::get('support_phone'),
            'email'       => self::get('support_email'),
            'whatsapp'    => self::get('support_whatsapp'),
            'address'     => self::get('address'),
        ];
    }
}

/** Short accessor used throughout the templates. */
function setting(string $key, ?string $default = null): string
{
    return Settings::get($key, $default);
}

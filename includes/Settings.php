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
        'product_placeholder_url' => '',
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
        'intro_video_enabled'   => '0',
        'intro_video_url'       => '',
        'currency'              => 'LKR',
        'currency_symbol'       => 'Rs',
        'logo_url'              => '',
        'favicon_url'           => '',
        'brand_color'           => '#0c800f',
        'brand_color_dark'      => '#0a6510',
        'accent_color'          => '#0a57a0',
        'theme_header_bg' => '#ffffff',
        'theme_header_text' => '#0f172a',
        'theme_announcement_bg' => '#0a6510',
        'theme_announcement_text' => '#ffffff',
        'theme_input_bg' => '#ffffff',
        'theme_input_text' => '#1e293b',
        'theme_admin_sidebar_bg' => '#0f1f14',
        'theme_admin_sidebar_text' => '#cfe9d5',
        'theme_admin_active_bg' => '#0c800f',
        'theme_nav_bg' => '#ffffff',
        'theme_nav_text' => '#44536a',
        'theme_page_bg' => '#f8fafc',
        'theme_surface_bg' => '#ffffff',
        'theme_border_color' => '#e2e8f0',
        'theme_text_color' => '#1e293b',
        'theme_heading_color' => '#0f172a',
        'theme_muted_color' => '#566376',
        'theme_link_color' => '#0a6510',
        'theme_link_hover_color' => '#084213',
        'theme_button_bg' => '#0c800f',
        'theme_button_text' => '#ffffff',
        'theme_button_hover_bg' => '#0a6510',
        'theme_button_hover_text' => '#ffffff',
        'theme_secondary_button_bg' => '#f1f5f9',
        'theme_secondary_button_text' => '#334155',
        'theme_secondary_button_hover_bg' => '#e2e8f0',
        'theme_secondary_button_hover_text' => '#0f172a',
        'theme_wishlist_icon' => '#44536a',
        'theme_wishlist_bg' => '#ffffff',
        'theme_wishlist_hover_icon' => '#e11d48',
        'theme_wishlist_hover_bg' => '#ffffff',
        'theme_wishlist_saved_icon' => '#e11d48',
        'theme_wishlist_saved_bg' => '#ffffff',
        'theme_discount_badge_bg' => '#e11d48',
        'theme_discount_badge_text' => '#ffffff',
        'theme_choice_badge_bg' => '#24b36b',
        'theme_choice_badge_text' => '#ffffff',
        'theme_featured_badge_bg' => '#3979ed',
        'theme_featured_badge_text' => '#ffffff',
        'theme_footer_bg' => '#f8fafc',
        'theme_footer_text' => '#44536a',
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
        'linkedin_url'          => '',
        'pinterest_url'         => '',
        'snapchat_url'          => '',
        'telegram_url'          => '',
        'discord_url'           => '',
        'threads_url'           => '',
        'reddit_url'            => '',
        'twitch_url'            => '',
        'messenger_url'         => '',
        'wechat_url'            => '',
        'line_url'              => '',
        'mastodon_url'          => '',
        'vimeo_url'             => '',
        'spotify_url'           => '',
        'medium_url'            => '',
        'behance_url'           => '',
        'wordpress_url'         => '',
        'github_url'            => '',
        'footer_copyright'      => '© {year} {store}. All rights reserved.',
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

    /** Return a saved six-digit hex colour, falling back on the trusted default. */
    public static function color(string $key): string
    {
        $default = (string) (self::DEFAULTS[$key] ?? '#000000');
        $value = self::get($key, $default);
        return preg_match('/^#[0-9a-f]{6}$/iD', $value) ? strtolower($value) : $default;
    }

    /** CSS declarations for the configurable storefront/admin colour palette. */
    public static function themeCssVars(): string
    {
        $map = [
            '--brand' => 'brand_color',
            '--brand-600' => 'brand_color_dark',
            '--accent' => 'accent_color',
            '--ui-header-bg' => 'theme_header_bg',
            '--ui-header-text' => 'theme_header_text',
            '--ui-announcement-bg' => 'theme_announcement_bg',
            '--ui-announcement-text' => 'theme_announcement_text',
            '--ui-input-bg' => 'theme_input_bg',
            '--ui-input-text' => 'theme_input_text',
            '--ui-admin-sidebar-bg' => 'theme_admin_sidebar_bg',
            '--ui-admin-sidebar-text' => 'theme_admin_sidebar_text',
            '--ui-admin-active-bg' => 'theme_admin_active_bg',
            '--ui-nav-bg' => 'theme_nav_bg',
            '--ui-nav-text' => 'theme_nav_text',
            '--ui-page-bg' => 'theme_page_bg',
            '--ui-surface-bg' => 'theme_surface_bg',
            '--ui-border' => 'theme_border_color',
            '--ui-text' => 'theme_text_color',
            '--ui-heading' => 'theme_heading_color',
            '--ui-muted' => 'theme_muted_color',
            '--ui-link' => 'theme_link_color',
            '--ui-link-hover' => 'theme_link_hover_color',
            '--ui-button-bg' => 'theme_button_bg',
            '--ui-button-text' => 'theme_button_text',
            '--ui-button-hover-bg' => 'theme_button_hover_bg',
            '--ui-button-hover-text' => 'theme_button_hover_text',
            '--ui-secondary-button-bg' => 'theme_secondary_button_bg',
            '--ui-secondary-button-text' => 'theme_secondary_button_text',
            '--ui-secondary-button-hover-bg' => 'theme_secondary_button_hover_bg',
            '--ui-secondary-button-hover-text' => 'theme_secondary_button_hover_text',
            '--ui-wishlist-icon' => 'theme_wishlist_icon',
            '--ui-wishlist-bg' => 'theme_wishlist_bg',
            '--ui-wishlist-hover-icon' => 'theme_wishlist_hover_icon',
            '--ui-wishlist-hover-bg' => 'theme_wishlist_hover_bg',
            '--ui-wishlist-saved-icon' => 'theme_wishlist_saved_icon',
            '--ui-wishlist-saved-bg' => 'theme_wishlist_saved_bg',
            '--ui-discount-badge-bg' => 'theme_discount_badge_bg',
            '--ui-discount-badge-text' => 'theme_discount_badge_text',
            '--ui-choice-badge-bg' => 'theme_choice_badge_bg',
            '--ui-choice-badge-text' => 'theme_choice_badge_text',
            '--ui-featured-badge-bg' => 'theme_featured_badge_bg',
            '--ui-featured-badge-text' => 'theme_featured_badge_text',
            '--ui-footer-bg' => 'theme_footer_bg',
            '--ui-footer-text' => 'theme_footer_text',
            '--bs-primary' => 'theme_button_bg',
            '--bs-body-bg' => 'theme_page_bg',
            '--bs-body-color' => 'theme_text_color',
            '--bs-secondary-bg' => 'theme_surface_bg',
            '--bs-tertiary-bg' => 'theme_page_bg',
            '--bs-secondary-color' => 'theme_muted_color',
            '--bs-border-color' => 'theme_border_color',
            '--bs-link-color' => 'theme_link_color',
            '--bs-link-hover-color' => 'theme_link_hover_color',
            '--ink-50' => 'theme_page_bg',
            '--ink-100' => 'theme_secondary_button_bg',
            '--ink-200' => 'theme_border_color',
            '--ink-300' => 'theme_border_color',
            '--ink-400' => 'theme_muted_color',
            '--ink-500' => 'theme_muted_color',
            '--ink-600' => 'theme_muted_color',
            '--ink-700' => 'theme_text_color',
            '--ink-800' => 'theme_text_color',
            '--ink-900' => 'theme_heading_color',
        ];
        $declarations = [];
        foreach ($map as $variable => $key) {
            $declarations[] = $variable . ':' . self::color($key);
        }
        foreach ([
            '--bs-primary-rgb' => 'theme_button_bg',
            '--bs-body-color-rgb' => 'theme_text_color',
            '--bs-secondary-color-rgb' => 'theme_muted_color',
            '--bs-link-color-rgb' => 'theme_link_color',
            '--bs-link-hover-color-rgb' => 'theme_link_hover_color',
        ] as $variable => $key) {
            $hex = self::color($key);
            $rgb = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
            $declarations[] = $variable . ':' . implode(',', $rgb);
        }
        return implode(';', $declarations);
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
            'color'       => self::color('brand_color'),
            'color_dark'  => self::color('brand_color_dark'),
            'accent'      => self::color('accent_color'),
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

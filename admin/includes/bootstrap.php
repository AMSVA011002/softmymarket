<?php
/**
 * Dashboard guard + shared helpers.
 *
 * Every page under /admin requires a signed-in team member (admin or staff).
 * Individual pages then call admin_require('permission') before doing work.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

Auth::requireLogin();
if (!Auth::isTeam()) {
    abort(403, 'The dashboard is limited to store staff.');
}

$adminUser = Auth::user();
$adminBrand = Settings::brand();

/** Permission gate for a dashboard page. */
function admin_require(string $permission): void
{
    if (!Auth::can($permission)) {
        abort(403, 'You do not have permission to view this page.');
    }
}

/** Build a dashboard URL. */
function admin_url(string $path = '', array $query = []): string
{
    $url = '/admin/' . ltrim($path, '/');
    if ($query !== []) {
        $url .= '?' . http_build_query(array_filter($query, static fn ($v) => $v !== '' && $v !== null));
    }
    return $url;
}

/** True when the current dashboard page matches $path. */
function admin_active(string $path): bool
{
    $current = basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '/admin/index.php', PHP_URL_PATH));
    return $current === $path;
}

/** Counts used by the sidebar badges (cached per request). */
function admin_nav_counts(): array
{
    static $counts = null;
    if ($counts !== null) {
        return $counts;
    }
    $counts = [
        'orders' => Database::int("SELECT COUNT(*) FROM orders WHERE status IN ('PENDING', 'CONFIRMED', 'PACKED')"),
        'payments' => Database::int("SELECT COUNT(*) FROM payments WHERE status = 'pending' AND gateway = 'BANK_TRANSFER'"),
        'reviews' => Database::int('SELECT COUNT(*) FROM reviews WHERE status = :s', ['s' => 'pending']),
        'messages' => Database::int("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'"),
        'low_stock' => Database::int('SELECT COUNT(*) FROM products WHERE stock <= low_stock_alert AND status = :s2', ['s2' => 'active']),
    ];
    return $counts;
}

/** Grouped navigation definition: label, href, icon, permission, badge key. */
function admin_nav_groups(): array
{
    return [
        'Store' => [
            ['Dashboard', 'index.php', 'bi-grid-1x2', 'dashboard.view', null],
            ['Live orders', 'live.php', 'bi-broadcast-pin', 'liveboard.view', null],
            ['Orders', 'orders.php', 'bi-box-seam', 'orders.view', 'orders'],
            ['Payments', 'payments.php', 'bi-credit-card', 'payments.verify', 'payments'],
        ],
        'Catalogue' => [
            ['Products', 'products.php', 'bi-bag', 'products.manage', 'low_stock'],
            ['Categories', 'categories.php', 'bi-diagram-3', 'products.manage', null],
            ['Brands', 'brands.php', 'bi-award', 'products.manage', null],
            ['Inventory', 'inventory.php', 'bi-boxes', 'inventory.manage', null],
            ['Coupons', 'coupons.php', 'bi-ticket-perforated', 'coupons.view', null],
            ['Reviews', 'reviews.php', 'bi-star-half', 'reviews.manage', 'reviews'],
        ],
        'People' => [
            ['Customers', 'customers.php', 'bi-people', 'customers.view', null],
            ['Messages', 'messages.php', 'bi-chat-dots', 'messages.view', 'messages'],
        ],
        'Insights' => [
            ['Reports', 'reports.php', 'bi-graph-up-arrow', 'analytics.view', null],
            ['Audit log', 'audit.php', 'bi-shield-lock', 'settings.manage', null],
        ],
        'System' => [
            ['Settings', 'settings.php', 'bi-gear', 'settings.manage', null],
            ['Team', 'team.php', 'bi-person-badge', 'users.manage', null],
            ['Tools', 'tools.php', 'bi-tools', 'settings.manage', null],
        ],
    ];
}

/**
 * Editable settings, grouped for the settings page.
 *
 * The shape is [group => [key => [label, type, default, help?, options?]]].
 * admin/actions.php uses the same map to decide which keys a form may write.
 *
 * @return array<string,array<string,array<string,mixed>>>
 */
function admin_setting_groups(): array
{
    return [
        'general' => [
            'store_name' => ['label' => 'Store name', 'type' => 'text', 'default' => 'SoftMyMarket'],
            'store_tagline' => ['label' => 'Tagline', 'type' => 'text', 'default' => 'Sri Lanka\'s everyday marketplace'],
            'logo_url' => ['label' => 'Logo URL', 'type' => 'text', 'default' => '', 'help' => 'Public image or Google Drive share link. Leave blank to use the initials mark.'],
            'brand_color' => ['label' => 'Brand colour', 'type' => 'color', 'default' => '#0c800f'],
            'brand_color_dark' => ['label' => 'Brand colour (dark)', 'type' => 'color', 'default' => '#0a6510'],
            'accent_color' => ['label' => 'Accent colour', 'type' => 'color', 'default' => '#0a57a0'],
            'currency' => ['label' => 'Currency code', 'type' => 'text', 'default' => 'LKR'],
            'currency_symbol' => ['label' => 'Currency symbol', 'type' => 'text', 'default' => 'Rs'],
            'support_email' => ['label' => 'Support email', 'type' => 'email', 'default' => 'hello@softmymarket.lk'],
            'support_phone' => ['label' => 'Support phone', 'type' => 'text', 'default' => '+94 11 234 5678'],
            'support_whatsapp' => ['label' => 'WhatsApp number', 'type' => 'text', 'default' => '+94 77 234 5678'],
            'address' => ['label' => 'Store address', 'type' => 'textarea', 'default' => '128 Galle Road, Colombo 03, Sri Lanka'],
            'announcement' => ['label' => 'Announcement bar', 'type' => 'text', 'default' => 'Island-wide delivery in 1–4 days · Free shipping over Rs 15,000', 'help' => 'Leave blank to hide the bar.'],
            'footer_note' => ['label' => 'Footer note', 'type' => 'textarea', 'default' => 'SoftMyMarket is a single-vendor marketplace operated in Colombo, Sri Lanka.'],
            'facebook_url' => ['label' => 'Facebook URL', 'type' => 'text', 'default' => ''],
            'instagram_url' => ['label' => 'Instagram URL', 'type' => 'text', 'default' => ''],
            'whatsapp_url' => ['label' => 'WhatsApp link', 'type' => 'text', 'default' => ''],
            'youtube_url' => ['label' => 'YouTube URL', 'type' => 'text', 'default' => ''],
            'tiktok_url' => ['label' => 'TikTok URL', 'type' => 'text', 'default' => ''],
            'default_meta_title' => ['label' => 'Default meta title', 'type' => 'text', 'default' => 'SoftMyMarket — online shopping in Sri Lanka'],
            'default_meta_description' => ['label' => 'Default meta description', 'type' => 'textarea', 'default' => ''],
            'google_analytics_id' => ['label' => 'Google Analytics ID', 'type' => 'text', 'default' => ''],
            'meta_pixel_id' => ['label' => 'Meta Pixel ID', 'type' => 'text', 'default' => ''],
        ],
        'commerce' => [
            'default_shipping_fee' => ['label' => 'Default shipping fee', 'type' => 'number', 'default' => 450],
            'free_shipping_threshold' => ['label' => 'Free shipping over', 'type' => 'number', 'default' => 15000],
            'tax_percent' => ['label' => 'Tax / VAT percent', 'type' => 'number', 'default' => 0, 'help' => 'Applied to the discounted subtotal.'],
            'low_stock_threshold' => ['label' => 'Low stock threshold', 'type' => 'number', 'default' => 5],
            'order_prefix' => ['label' => 'Order number prefix', 'type' => 'text', 'default' => 'SMM'],
            'allow_guest_checkout' => ['label' => 'Allow guest checkout', 'type' => 'bool', 'default' => 1],
            'allow_reviews' => ['label' => 'Allow product reviews', 'type' => 'bool', 'default' => 1],
            'maintenance_mode' => ['label' => 'Maintenance mode', 'type' => 'bool', 'default' => 0, 'help' => 'Storefront shows a holding page; the dashboard stays available.'],
            'live_tracking_refresh' => ['label' => 'Live tracking refresh (seconds)', 'type' => 'number', 'default' => 15],
            'gift_wrap_fee' => ['label' => 'Gift wrap fee', 'type' => 'number', 'default' => 0],
        ],
        'content' => [
            'about_text' => ['label' => 'About us', 'type' => 'textarea', 'default' => ''],
            'shipping_text' => ['label' => 'Shipping & delivery', 'type' => 'textarea', 'default' => ''],
            'returns_text' => ['label' => 'Returns & refunds', 'type' => 'textarea', 'default' => ''],
            'terms_text' => ['label' => 'Terms of service', 'type' => 'textarea', 'default' => ''],
            'privacy_text' => ['label' => 'Privacy policy', 'type' => 'textarea', 'default' => ''],
            'bank_transfer_note' => ['label' => 'Bank transfer note', 'type' => 'textarea', 'default' => ''],
            'tracking_note' => ['label' => 'Tracking page note', 'type' => 'textarea', 'default' => ''],
        ],
    ];
}

/** Payment gateway fields exposed in the dashboard (stored as gateway_config JSON). */
function admin_gateway_fields(): array
{
    return [
        'cod' => [
            'label' => 'Cash on delivery',
            'icon' => 'bi-cash-coin',
            'fields' => [
                'enabled' => ['label' => 'Enable', 'type' => 'bool', 'default' => 1],
                'fee' => ['label' => 'COD handling fee', 'type' => 'number', 'default' => 0],
                'max_total' => ['label' => 'Maximum order value', 'type' => 'number', 'default' => 150000],
            ],
        ],
        'bank_transfer' => [
            'label' => 'Bank transfer',
            'icon' => 'bi-bank',
            'fields' => [
                'enabled' => ['label' => 'Enable', 'type' => 'bool', 'default' => 1],
                'instructions' => ['label' => 'Instructions shown to the customer', 'type' => 'textarea', 'default' => ''],
            ],
        ],
        'seylan' => [
            'label' => 'Seylan Bank card gateway',
            'icon' => 'bi-credit-card-2-front',
            'fields' => [
                'enabled' => ['label' => 'Enable', 'type' => 'bool', 'default' => 0],
                'mode' => ['label' => 'Mode', 'type' => 'select', 'default' => 'sandbox', 'options' => ['sandbox' => 'Sandbox', 'live' => 'Live']],
                'merchant_id' => ['label' => 'Merchant ID', 'type' => 'text', 'default' => ''],
                'api_key' => ['label' => 'API key', 'type' => 'text', 'default' => ''],
                'secret' => ['label' => 'Signing secret', 'type' => 'password', 'default' => ''],
                'live_url' => ['label' => 'Live payment URL', 'type' => 'text', 'default' => 'https://pay.seylanbank.lk/pg/payment'],
                'sandbox_url' => ['label' => 'Sandbox payment URL', 'type' => 'text', 'default' => 'https://sandbox.seylanbank.lk/pg/payment'],
                'simulate' => ['label' => 'Allow simulated payments when credentials are missing', 'type' => 'bool', 'default' => 1],
            ],
        ],
        'koko' => [
            'label' => 'KOKO — pay in 3',
            'icon' => 'bi-phone',
            'fields' => [
                'enabled' => ['label' => 'Enable', 'type' => 'bool', 'default' => 0],
                'mode' => ['label' => 'Mode', 'type' => 'select', 'default' => 'sandbox', 'options' => ['sandbox' => 'Sandbox', 'live' => 'Live']],
                'merchant_id' => ['label' => 'Merchant ID', 'type' => 'text', 'default' => ''],
                'api_key' => ['label' => 'API key', 'type' => 'text', 'default' => ''],
                'secret' => ['label' => 'Signing secret', 'type' => 'password', 'default' => ''],
                'min_total' => ['label' => 'Minimum order value', 'type' => 'number', 'default' => 0],
                'live_url' => ['label' => 'Live payment URL', 'type' => 'text', 'default' => ''],
                'sandbox_url' => ['label' => 'Sandbox payment URL', 'type' => 'text', 'default' => ''],
                'simulate' => ['label' => 'Allow simulated payments', 'type' => 'bool', 'default' => 1],
            ],
        ],
        'payzy' => [
            'label' => 'Payzy wallet',
            'icon' => 'bi-wallet2',
            'fields' => [
                'enabled' => ['label' => 'Enable', 'type' => 'bool', 'default' => 0],
                'mode' => ['label' => 'Mode', 'type' => 'select', 'default' => 'sandbox', 'options' => ['sandbox' => 'Sandbox', 'live' => 'Live']],
                'merchant_id' => ['label' => 'Merchant ID', 'type' => 'text', 'default' => ''],
                'secret' => ['label' => 'Signing secret', 'type' => 'password', 'default' => ''],
                'live_url' => ['label' => 'Live payment URL', 'type' => 'text', 'default' => ''],
                'sandbox_url' => ['label' => 'Sandbox payment URL', 'type' => 'text', 'default' => ''],
                'simulate' => ['label' => 'Allow simulated payments', 'type' => 'bool', 'default' => 1],
            ],
        ],
        'mintpay' => [
            'label' => 'Mintpay — pay later',
            'icon' => 'bi-calendar-check',
            'fields' => [
                'enabled' => ['label' => 'Enable', 'type' => 'bool', 'default' => 0],
                'mode' => ['label' => 'Mode', 'type' => 'select', 'default' => 'sandbox', 'options' => ['sandbox' => 'Sandbox', 'live' => 'Live']],
                'merchant_id' => ['label' => 'Merchant ID', 'type' => 'text', 'default' => ''],
                'secret' => ['label' => 'Signing secret', 'type' => 'password', 'default' => ''],
                'live_url' => ['label' => 'Live payment URL', 'type' => 'text', 'default' => ''],
                'sandbox_url' => ['label' => 'Sandbox payment URL', 'type' => 'text', 'default' => ''],
                'simulate' => ['label' => 'Allow simulated payments', 'type' => 'bool', 'default' => 1],
            ],
        ],
    ];
}

/** Read the effective gateway configuration (config file + dashboard overrides). */
function admin_gateway_config(): array
{
    $config = (array) Config::get('payments', []);
    $stored = json_decode(Settings::get('gateway_config', ''), true);
    if (is_array($stored)) {
        foreach ($stored as $key => $values) {
            if (!is_array($values)) {
                continue;
            }
            $config[$key] = array_merge((array) ($config[$key] ?? []), $values);
        }
    }
    return $config;
}

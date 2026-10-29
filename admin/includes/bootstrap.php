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
        'returns' => Database::int("SELECT COUNT(*) FROM return_requests WHERE status IN ('REQUESTED','APPROVED','IN_TRANSIT','RECEIVED','INSPECTING')"),
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
            ['Returns', 'returns.php', 'bi-arrow-counterclockwise', 'orders.view', 'returns'],
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
            'store_name' => ['label' => 'Store name', 'type' => 'text', 'default' => 'YourMarket'],
            'store_tagline' => ['label' => 'Tagline', 'type' => 'text', 'default' => 'Sri Lanka\'s everyday marketplace'],
            'store_description' => ['label' => 'Store description', 'type' => 'textarea', 'default' => 'Shop quality products from one trusted local store, with reliable delivery and helpful customer support.', 'help' => 'Used in the footer, search previews and structured store information.'],
            'store_language' => ['label' => 'Store language / locale', 'type' => 'text', 'default' => 'en-LK', 'help' => 'BCP 47 language tag used in page metadata, e.g. en-LK or si-LK.'],
            'store_locality' => ['label' => 'City / locality', 'type' => 'text', 'default' => 'Colombo'],
            'store_country' => ['label' => 'Country', 'type' => 'text', 'default' => 'Sri Lanka'],
            'store_country_code' => ['label' => 'Country code', 'type' => 'text', 'default' => 'LK', 'help' => 'Two-letter ISO country code used in structured data.'],
            'store_hours' => ['label' => 'Business hours', 'type' => 'text', 'default' => '', 'help' => 'Optional opening hours shown on the About page.'],
            'social_image_url' => ['label' => 'Social share image', 'type' => 'image', 'default' => '', 'help' => 'Optional image for link previews. Recommended 1200 × 630 px; Google Drive links and external https images are supported.'],
            'product_placeholder_url' => ['label' => 'Product image placeholder', 'type' => 'image', 'default' => '', 'help' => 'Shown automatically when a product has no image or its remote image cannot be loaded. Use a public Google Drive image link or HTTPS image URL. Recommended 4:3 or square image; leave blank to use the built-in placeholder.'],

            'logo_url' => [
                'label' => 'Store logo',
                'type' => 'image',
                'default' => '',
                'help' => 'Google Drive share link or any https image URL. Transparent PNG or SVG works best; leave blank to use the initials mark.',
            ],
            'favicon_url' => [
                'label' => 'Favicon',
                'type' => 'image',
                'default' => '',
                'help' => 'Square image, 512×512 recommended. Same link rules as the logo; leave blank to use the bundled icon.',
            ],
            'brand_color' => ['label' => 'Brand colour', 'type' => 'color', 'default' => '#0c800f'],
            'brand_color_dark' => ['label' => 'Brand colour (dark)', 'type' => 'color', 'default' => '#0a6510'],
            'accent_color' => ['label' => 'Accent colour', 'type' => 'color', 'default' => '#0a57a0'],
            'currency' => ['label' => 'Currency code', 'type' => 'text', 'default' => 'LKR'],
            'currency_symbol' => ['label' => 'Currency symbol', 'type' => 'text', 'default' => 'Rs'],
            'support_email' => ['label' => 'Support email', 'type' => 'email', 'default' => 'hello@yourmarket.example'],
            'orders_email' => ['label' => 'Store operations email', 'type' => 'email', 'default' => 'orders@yourmarket.example', 'help' => 'Operational alerts such as low-stock notifications are delivered here. Customer order confirmations go to the buyer.'],
            'support_phone' => ['label' => 'Support phone', 'type' => 'text', 'default' => '+94 11 234 5678'],
            'support_whatsapp' => ['label' => 'WhatsApp number', 'type' => 'text', 'default' => '+94 77 234 5678'],
            'address' => ['label' => 'Store address', 'type' => 'textarea', 'default' => '128 Galle Road, Colombo 03, Sri Lanka'],
            'announcement' => ['label' => 'Announcement bar', 'type' => 'text', 'default' => 'Welcome — delivery options and order details are shown at checkout.', 'help' => 'Leave blank to hide the bar.'],
            'footer_note' => ['label' => 'Footer note', 'type' => 'textarea', 'default' => 'Thank you for shopping with us.'],
            'facebook_url' => ['label' => 'Facebook URL', 'type' => 'url', 'default' => '', 'help' => 'Add the HTTPS profile/page URL. Only non-empty, valid links appear in the footer.'],
            'instagram_url' => ['label' => 'Instagram URL', 'type' => 'url', 'default' => ''],
            'tiktok_url' => ['label' => 'TikTok URL', 'type' => 'url', 'default' => ''],
            'youtube_url' => ['label' => 'YouTube URL', 'type' => 'url', 'default' => ''],
            'twitter_url' => ['label' => 'X (Twitter) URL', 'type' => 'url', 'default' => ''],
            'whatsapp_url' => ['label' => 'WhatsApp URL', 'type' => 'url', 'default' => ''],
            'linkedin_url' => ['label' => 'LinkedIn URL', 'type' => 'url', 'default' => ''],
            'pinterest_url' => ['label' => 'Pinterest URL', 'type' => 'url', 'default' => ''],
            'snapchat_url' => ['label' => 'Snapchat URL', 'type' => 'url', 'default' => ''],
            'telegram_url' => ['label' => 'Telegram URL', 'type' => 'url', 'default' => ''],
            'discord_url' => ['label' => 'Discord URL', 'type' => 'url', 'default' => ''],
            'threads_url' => ['label' => 'Threads URL', 'type' => 'url', 'default' => ''],
            'reddit_url' => ['label' => 'Reddit URL', 'type' => 'url', 'default' => ''],
            'twitch_url' => ['label' => 'Twitch URL', 'type' => 'url', 'default' => ''],
            'messenger_url' => ['label' => 'Messenger URL', 'type' => 'url', 'default' => ''],
            'wechat_url' => ['label' => 'WeChat URL', 'type' => 'url', 'default' => ''],
            'line_url' => ['label' => 'LINE URL', 'type' => 'url', 'default' => ''],
            'mastodon_url' => ['label' => 'Mastodon URL', 'type' => 'url', 'default' => ''],
            'vimeo_url' => ['label' => 'Vimeo URL', 'type' => 'url', 'default' => ''],
            'spotify_url' => ['label' => 'Spotify URL', 'type' => 'url', 'default' => ''],
            'medium_url' => ['label' => 'Medium URL', 'type' => 'url', 'default' => ''],
            'behance_url' => ['label' => 'Behance URL', 'type' => 'url', 'default' => ''],
            'wordpress_url' => ['label' => 'WordPress URL', 'type' => 'url', 'default' => ''],
            'github_url' => ['label' => 'GitHub URL', 'type' => 'url', 'default' => ''],
            'footer_copyright' => ['label' => 'Copyright line', 'type' => 'text', 'default' => '© {year} {store}. All rights reserved.', 'max_length' => 240, 'help' => 'Use {year} for the current year and {store} for the current store name. Enter plain text; HTML is escaped.'],

            'default_meta_title' => ['label' => 'Default meta title', 'type' => 'text', 'default' => '', 'help' => 'Leave blank to use the current store name and tagline.'],
            'default_meta_description' => ['label' => 'Default meta description', 'type' => 'textarea', 'default' => '', 'help' => 'Leave blank to use the store description.'],
            'google_analytics_id' => ['label' => 'Google Analytics ID', 'type' => 'text', 'default' => ''],
            'meta_pixel_id' => ['label' => 'Meta Pixel ID', 'type' => 'text', 'default' => ''],
        ],
        'appearance' => [
            'theme_header_bg' => ['label' => 'Header background', 'type' => 'color', 'default' => '#ffffff'],
            'theme_header_text' => ['label' => 'Header text and icons', 'type' => 'color', 'default' => '#0f172a'],
            'theme_announcement_bg' => ['label' => 'Announcement bar background', 'type' => 'color', 'default' => '#0a6510'],
            'theme_announcement_text' => ['label' => 'Announcement bar text', 'type' => 'color', 'default' => '#ffffff'],
            'theme_input_bg' => ['label' => 'Form input background', 'type' => 'color', 'default' => '#ffffff'],
            'theme_input_text' => ['label' => 'Form input text', 'type' => 'color', 'default' => '#1e293b'],
            'theme_admin_sidebar_bg' => ['label' => 'Admin sidebar background', 'type' => 'color', 'default' => '#0f1f14'],
            'theme_admin_sidebar_text' => ['label' => 'Admin sidebar text', 'type' => 'color', 'default' => '#cfe9d5'],
            'theme_admin_active_bg' => ['label' => 'Admin active navigation background', 'type' => 'color', 'default' => '#0c800f'],
            'theme_nav_bg' => ['label' => 'Category navigation background', 'type' => 'color', 'default' => '#ffffff'],
            'theme_nav_text' => ['label' => 'Category navigation text', 'type' => 'color', 'default' => '#44536a'],
            'theme_page_bg' => ['label' => 'Page background', 'type' => 'color', 'default' => '#f8fafc'],
            'theme_surface_bg' => ['label' => 'Cards and panels background', 'type' => 'color', 'default' => '#ffffff'],
            'theme_border_color' => ['label' => 'Card and input borders', 'type' => 'color', 'default' => '#e2e8f0'],
            'theme_text_color' => ['label' => 'Body text', 'type' => 'color', 'default' => '#1e293b'],
            'theme_heading_color' => ['label' => 'Headings', 'type' => 'color', 'default' => '#0f172a'],
            'theme_muted_color' => ['label' => 'Secondary / muted text', 'type' => 'color', 'default' => '#566376'],
            'theme_link_color' => ['label' => 'Links', 'type' => 'color', 'default' => '#0a6510'],
            'theme_link_hover_color' => ['label' => 'Links on hover', 'type' => 'color', 'default' => '#084213'],
            'theme_button_bg' => ['label' => 'Primary button background', 'type' => 'color', 'default' => '#0c800f'],
            'theme_button_text' => ['label' => 'Primary button text', 'type' => 'color', 'default' => '#ffffff'],
            'theme_button_hover_bg' => ['label' => 'Primary button hover background', 'type' => 'color', 'default' => '#0a6510'],
            'theme_button_hover_text' => ['label' => 'Primary button hover text', 'type' => 'color', 'default' => '#ffffff'],
            'theme_secondary_button_bg' => ['label' => 'Secondary button background', 'type' => 'color', 'default' => '#f1f5f9'],
            'theme_secondary_button_text' => ['label' => 'Secondary button text', 'type' => 'color', 'default' => '#334155'],
            'theme_secondary_button_hover_bg' => ['label' => 'Secondary button hover background', 'type' => 'color', 'default' => '#e2e8f0'],
            'theme_secondary_button_hover_text' => ['label' => 'Secondary button hover text', 'type' => 'color', 'default' => '#0f172a'],
            'theme_wishlist_icon' => ['label' => 'Wishlist icon', 'type' => 'color', 'default' => '#44536a'],
            'theme_wishlist_bg' => ['label' => 'Wishlist button background', 'type' => 'color', 'default' => '#ffffff'],
            'theme_wishlist_hover_icon' => ['label' => 'Wishlist hover icon', 'type' => 'color', 'default' => '#e11d48'],
            'theme_wishlist_hover_bg' => ['label' => 'Wishlist hover background', 'type' => 'color', 'default' => '#ffffff'],
            'theme_wishlist_saved_icon' => ['label' => 'Saved wishlist icon', 'type' => 'color', 'default' => '#e11d48'],
            'theme_wishlist_saved_bg' => ['label' => 'Saved wishlist background', 'type' => 'color', 'default' => '#ffffff'],
            'theme_discount_badge_bg' => ['label' => 'Discount badge background', 'type' => 'color', 'default' => '#e11d48'],
            'theme_discount_badge_text' => ['label' => 'Discount badge text', 'type' => 'color', 'default' => '#ffffff'],
            'theme_choice_badge_bg' => ['label' => 'Choice badge background', 'type' => 'color', 'default' => '#24b36b'],
            'theme_choice_badge_text' => ['label' => 'Choice badge text', 'type' => 'color', 'default' => '#ffffff'],
            'theme_featured_badge_bg' => ['label' => 'Featured badge background', 'type' => 'color', 'default' => '#3979ed'],
            'theme_featured_badge_text' => ['label' => 'Featured badge text', 'type' => 'color', 'default' => '#ffffff'],
            'theme_footer_bg' => ['label' => 'Footer background', 'type' => 'color', 'default' => '#f8fafc'],
            'theme_footer_text' => ['label' => 'Footer text and links', 'type' => 'color', 'default' => '#44536a'],
        ],
        'email' => [
            'mail_transport' => ['label' => 'Email transport', 'type' => 'select', 'default' => 'smtp', 'options' => ['smtp' => 'Authenticated SMTP', 'mail' => 'Server mail()'], 'help' => 'SMTP is recommended. The sender must be permitted by the mail account.'],
            'mail_from_email' => ['label' => 'From email address', 'type' => 'email', 'default' => '', 'help' => 'Use the mailbox address authorised by your SMTP provider.'],
            'mail_from_name' => ['label' => 'From name', 'type' => 'text', 'default' => '', 'help' => 'Leave blank to use the current store name.'],
            'mail_reply_to' => ['label' => 'Reply-to address', 'type' => 'email', 'default' => ''],
            'mail_smtp_host' => ['label' => 'SMTP server', 'type' => 'text', 'default' => '', 'help' => 'Enter the outgoing server hostname from your email provider. Do not include a protocol or slash.'],
            'mail_smtp_port' => ['label' => 'SMTP port', 'type' => 'number', 'default' => 465],
            'mail_smtp_encryption' => ['label' => 'Connection security', 'type' => 'select', 'default' => 'ssl', 'options' => ['ssl' => 'SSL/TLS (implicit)', 'tls' => 'STARTTLS'], 'help' => 'Use the secure SSL/TLS port recommended by your provider. SMTP authentication is always required.'],
            'mail_smtp_username' => ['label' => 'SMTP username', 'type' => 'text', 'default' => ''],
            'mail_smtp_password' => ['label' => 'SMTP password', 'type' => 'password', 'default' => '', 'help' => 'Saved encrypted. Leave blank to keep the existing password.'],
            'mail_smtp_timeout' => ['label' => 'Connection timeout (seconds)', 'type' => 'number', 'default' => 15],
        ],
        'commerce' => [
            'default_shipping_fee' => ['label' => 'Default shipping fee', 'type' => 'number', 'default' => 450],
            'free_shipping_threshold' => ['label' => 'Free shipping over', 'type' => 'number', 'default' => 15000],
            'tax_percent' => ['label' => 'Tax / VAT percent', 'type' => 'number', 'default' => 0, 'help' => 'Applied to the discounted subtotal.'],
            'low_stock_threshold' => ['label' => 'Low stock threshold', 'type' => 'number', 'default' => 5],
            'order_prefix' => ['label' => 'Order number prefix', 'type' => 'text', 'default' => 'YM'],
            'return_window_days' => ['label' => 'Return request window (days)', 'type' => 'number', 'default' => 7, 'help' => 'Customer requests are accepted this many days after delivery (1–90 days). Damaged/wrong-item reports remain limited to 48 hours.'],
            'allow_guest_checkout' => ['label' => 'Allow guest checkout', 'type' => 'bool', 'default' => 1],
            'allow_reviews' => ['label' => 'Allow product reviews', 'type' => 'bool', 'default' => 1],
            'maintenance_mode' => [
                'label' => 'Maintenance mode',
                'type' => 'bool',
                'default' => 0,
                'help' => 'Shows the holding page to all storefront visitors, including signed-in staff (HTTP 503). The admin dashboard remains reachable so the team can turn the store back on; login is available while registration remains blocked.'
            ],
            'live_tracking_refresh' => ['label' => 'Live tracking refresh (seconds)', 'type' => 'number', 'default' => 15],
        ],
        'content' => [
            'about_text' => ['label' => 'About us', 'type' => 'textarea', 'default' => ''],
            'faq_items' => ['label' => 'Custom help-centre FAQs', 'type' => 'textarea', 'default' => '', 'help' => 'Optional: one FAQ per line as Question | Answer. Leave blank to use the built-in FAQs.'],
            'shipping_text' => ['label' => 'Shipping & delivery', 'type' => 'textarea', 'default' => ''],
            'returns_text' => ['label' => 'Returns & refunds', 'type' => 'textarea', 'default' => ''],
            'terms_text' => ['label' => 'Terms of service', 'type' => 'textarea', 'default' => ''],
            'privacy_text' => ['label' => 'Privacy policy', 'type' => 'textarea', 'default' => ''],
            'bank_transfer_note' => ['label' => 'Bank transfer note', 'type' => 'textarea', 'default' => ''],
            'tracking_note' => ['label' => 'Tracking page note', 'type' => 'textarea', 'default' => ''],
            'delivery_promise' => ['label' => 'Typical delivery promise', 'type' => 'text', 'default' => '1–4 working days'],
            'support_response_time' => ['label' => 'Support response-time text', 'type' => 'text', 'default' => 'within one business day'],
            'trust_delivery_title' => ['label' => 'Trust bar: delivery heading', 'type' => 'text', 'default' => 'Island-wide delivery'],
            'trust_delivery_text' => ['label' => 'Trust bar: delivery text', 'type' => 'text', 'default' => 'Delivery times are shown at checkout'],
            'trust_payment_title' => ['label' => 'Trust bar: payment heading', 'type' => 'text', 'default' => 'Flexible payments'],
            'trust_payment_text' => ['label' => 'Trust bar: payment text', 'type' => 'text', 'default' => 'Available methods appear at checkout'],
            'trust_quality_title' => ['label' => 'Trust bar: product heading', 'type' => 'text', 'default' => 'Carefully selected products'],
            'trust_quality_text' => ['label' => 'Trust bar: product text', 'type' => 'text', 'default' => 'Product details shown on each listing'],
            'trust_returns_title' => ['label' => 'Trust bar: support heading', 'type' => 'text', 'default' => 'Customer care'],
            'trust_returns_text' => ['label' => 'Trust bar: support text', 'type' => 'text', 'default' => 'Contact our team if you need help'],
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
        'payhere' => [
            'label' => 'PayHere',
            'icon' => 'bi-credit-card',
            'fields' => [
                'enabled' => ['label' => 'Enable', 'type' => 'bool', 'default' => 0],
                'mode' => ['label' => 'Mode', 'type' => 'select', 'default' => 'sandbox', 'options' => ['sandbox' => 'Sandbox', 'live' => 'Live']],
                'merchant_id' => ['label' => 'Merchant ID', 'type' => 'text', 'default' => ''],
                'merchant_secret' => ['label' => 'Merchant secret', 'type' => 'password', 'default' => ''],
                'currency' => ['label' => 'Currency', 'type' => 'select', 'default' => 'LKR', 'options' => ['LKR' => 'LKR', 'USD' => 'USD']],
                'min_total' => ['label' => 'Minimum order value', 'type' => 'number', 'default' => 0],
                'max_total' => ['label' => 'Maximum order value', 'type' => 'number', 'default' => 0],
                'simulate' => ['label' => 'Use local sandbox simulation only when sandbox credentials are missing', 'type' => 'bool', 'default' => 1],
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

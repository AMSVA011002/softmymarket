<?php
/**
 * SoftMyMarket deployment self-check.
 *
 *   php tools/deploy-check.php
 *
 * Verifies the things that actually break a fresh deployment: config file,
 * database connectivity and privileges, schema completeness, the admin account,
 * writable folders, required PHP extensions and gateway configuration.
 *
 * Exit code 0 = ready to take traffic, 1 = at least one blocking problem.
 * Read-only: it never changes data.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line: php tools/deploy-check.php');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$blocking = 0;
$warnings = 0;

function check(string $label, bool $ok, string $fix = '', bool $blocking = true, string $detail = ''): void
{
    global $blocking, $warnings;
    if ($ok) {
        printf("  \033[32m✓\033[0m %-46s %s\n", $label, $detail !== '' ? $detail : '');
        return;
    }
    if ($blocking) {
        $blocking++;
        printf("  \033[31m✗\033[0m %-46s %s\n", $label, $fix);
    } else {
        $warnings++;
        printf("  \033[33m!\033[0m %-46s %s\n", $label, $fix);
    }
}

echo "SoftMyMarket deployment check\n";
echo "PHP " . PHP_VERSION . " · " . PHP_SAPI . " · " . date('d M Y H:i') . "\n\n";

/* -------------------------------------------------------------- environment */
echo "Environment\n";
check('config/config.php exists', is_file(ROOT_PATH . '/config/config.php'),
    'Copy config/config.example.php to config/config.php and fill in the database details.');
check('app.key is 64 hex characters', (bool) preg_match('/^[a-f0-9]{64}$/i', (string) Config::get('app.key', '')),
    'Generate one: php -r "echo bin2hex(random_bytes(32));"', true, strlen((string) Config::get('app.key', '')) . ' chars');
check('debug mode is off', Config::get('app.debug') === false, 'Set app.debug => false in config/config.php.');
check('timezone', true, '', true, (string) Config::get('app.timezone'));
check('application URL', true, '', true, Config::get('app.url') !== '' ? (string) Config::get('app.url') : 'auto-detected per request');

foreach ([
    'pdo_mysql' => extension_loaded('pdo_mysql'),
    'mbstring' => extension_loaded('mbstring'),
    'json' => extension_loaded('json'),
    'fileinfo' => extension_loaded('fileinfo'),
] as $extension => $loaded) {
    check("extension: {$extension}", $loaded, "Ask the host to enable {$extension} (or pick a PHP version that has it).");
}
foreach ([
    'openssl' => extension_loaded('openssl'),
    'curl' => function_exists('curl_init'),
    'gd' => function_exists('imagecreatefromstring'),
] as $extension => $loaded) {
    check("extension: {$extension} (optional)", $loaded,
        $extension === 'openssl'
            ? 'Only used by future gateway helpers; nothing in the current checkout needs it.'
            : "Without {$extension} the image proxy / slip resizing uses a slower fallback.",
        false);
}

foreach ([
    'storage/' => STORAGE_PATH,
    'storage/logs/' => STORAGE_PATH . '/logs',
    'storage/cache/' => STORAGE_PATH . '/cache',
    'storage/uploads/' => STORAGE_PATH . '/uploads',
] as $label => $path) {
    check("writable: {$label}", is_dir($path) && is_writable($path), "chmod 775 {$label} (directories are 755 by default).");
}

/* ----------------------------------------------------------------- database */
echo "\nDatabase\n";
$pdo = null;
try {
    $pdo = Database::pdo();
    check('connection', true, '', true, (string) $pdo->query('SELECT VERSION()')->fetchColumn());
} catch (Throwable $e) {
    check('connection', false, $e->getMessage());
    echo "\n\033[31mCannot continue without a database connection.\033[0m\n";
    echo "  host: " . Config::get('db.host') . ":" . Config::get('db.port') . "\n";
    echo "  name: " . Config::get('db.name') . "\n";
    echo "  user: " . Config::get('db.user') . "\n";
    exit(1);
}

$expectedTables = ['users', 'password_resets', 'user_addresses', 'categories', 'brands', 'products',
    'reviews', 'cart_items', 'wishlist_items', 'orders', 'order_items', 'order_events', 'payments',
    'coupons', 'coupon_redemptions', 'inventory_movements', 'notifications', 'settings',
    'rate_limits', 'audit_log', 'newsletter_subscribers', 'contact_messages', 'search_log'];

$existing = [];
foreach (Database::all('SHOW TABLES') as $row) {
    $existing[] = (string) array_values($row)[0];
}
$missing = array_values(array_diff($expectedTables, $existing));
check('schema imported (23 tables)', $missing === [],
    'Import database/install.sql in phpMyAdmin. Missing: ' . implode(', ', array_slice($missing, 0, 6)),
    true, count($existing) . ' tables found');

if ($missing === []) {
    $admins = Database::int("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'");
    check('administrator account', $admins > 0, 'Import database/install.sql, or create an admin in the database.', true, $admins . ' active');

    $plain = Database::int("SELECT COUNT(*) FROM users WHERE password_hash NOT LIKE '\\$2y\\$%' AND password_hash NOT LIKE '\\$2a\\$%'");
    check('all passwords hashed (bcrypt)', $plain === 0, "{$plain} account(s) do not look like bcrypt hashes.");

    $products = Database::int('SELECT COUNT(*) FROM products');
    check('catalogue has products', $products > 0,
        'Import database/demo-data.sql, or Admin → Tools → "Seed demo data".', false, number_format($products) . ' products');

    $categories = Database::int('SELECT COUNT(*) FROM categories');
    check('categories exist', $categories > 0,
        'Import database/demo-data.sql, or Admin → Tools → "Seed demo data".', false, number_format($categories) . ' categories');

    $orphans = Database::int(
        'SELECT COUNT(*) FROM categories c LEFT JOIN categories p ON p.id = c.parent_id
          WHERE c.parent_id IS NOT NULL AND p.id IS NULL'
    );
    check('no orphaned categories', $orphans === 0, "{$orphans} category row(s) point at a missing parent.");

    $settings = Database::int('SELECT COUNT(*) FROM settings');
    check('settings seeded', $settings > 0, 'Re-import database/install.sql (the settings block).', false, $settings . ' keys');

    // Write privileges are what shared hosts usually forget.
    $canWrite = true;
    $writeError = '';
    try {
        Database::run('CREATE TEMPORARY TABLE smm_write_test (id INT)');
        Database::run('DROP TEMPORARY TABLE smm_write_test');
    } catch (Throwable $e) {
        $canWrite = false;
        $writeError = $e->getMessage();
    }
    check('CREATE TEMPORARY TABLE privilege', $canWrite,
        'Attach the MySQL user to the database with full privileges, or import the *.sql files in phpMyAdmin.' . ($writeError !== '' ? ' (' . $writeError . ')' : ''), false);
}

/* ------------------------------------------------------------------- runtime */
echo "\nApplication\n";
$lock = STORAGE_PATH . '/installed.lock';
check('installer locked (storage/installed.lock)', is_file($lock),
    'Admin → Tools → "Lock the installer", or create the file by hand.', false);

$gateways = PaymentManager::gateways();
$enabled = [];
foreach ($gateways as $gateway) {
    if ($gateway->enabled()) {
        $enabled[] = $gateway->key;
    }
}

check('payment methods enabled', $enabled !== [], 'Admin → Settings → Payments — enable COD or bank transfer at minimum.');

$simulating = [];
foreach ($gateways as $gateway) {
    if ($gateway->enabled() && !empty($gateway->config['simulate'])) {
        $simulating[] = $gateway->key;
    }
}
check('no enabled gateway is simulating', $simulating === [],
    'Set simulate => false for: ' . implode(', ', $simulating), false);

check('mail transport configured', (string) Config::get('mail.transport', 'mail') !== 'log',
    'mail.transport is "log" — no email will be sent.', false, (string) Config::get('mail.transport'));

$storageRoot = realpath(STORAGE_PATH);
check('storage is outside the web root or protected', (bool) preg_match('#/storage$#', (string) $storageRoot),
    'Move storage/ above public_html, or keep storage/.htaccess in place.');
check('storage/.htaccess present', is_file(STORAGE_PATH . '/.htaccess'), 'Restore the deny-all .htaccess in storage/.');

echo "\n" . str_repeat('-', 70) . "\n";
if ($blocking === 0) {
    printf("\033[32mReady.\033[0m %d warning(s).\n", $warnings);
    echo "Sign in at /login.php and change the seeded administrator password.\n";
    exit(0);
}
printf("\033[31m%d blocking problem(s)\033[0m and %d warning(s).\n", $blocking, $warnings);
exit(1);

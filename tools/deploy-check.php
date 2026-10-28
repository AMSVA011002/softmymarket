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
    'coupons', 'coupon_redemptions', 'inventory_movements', 'return_requests', 'return_request_items', 'return_events', 'email_verifications', 'notifications', 'settings',
    'rate_limits', 'audit_log', 'newsletter_subscribers', 'contact_messages', 'search_log'];

$existing = [];
foreach (Database::all('SHOW TABLES') as $row) {
    $existing[] = (string) array_values($row)[0];
}
$missing = array_values(array_diff($expectedTables, $existing));
check('schema imported (27 tables)', $missing === [],
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

/* --------------------------------------------------------------- SQL safety */
echo "\nSQL safety\n";
$databaseSource = is_file(ROOT_PATH . '/includes/Database.php')
    ? (string) file_get_contents(ROOT_PATH . '/includes/Database.php')
    : '';
check('Database.php quotes identifiers', str_contains($databaseSource, 'function quote(')
    && str_contains($databaseSource, 'function quoteTable('),
    'includes/Database.php is out of date — upload the current release (an unquoted '
    . 'reserved word such as `condition` breaks product saves with error 1064).');

if (is_file(ROOT_PATH . '/tools/sql-audit.php')) {
    require_once ROOT_PATH . '/tools/sql-audit.php';
    $sqlFindings = smm_sql_reserved_audit(ROOT_PATH);
    $detail = $sqlFindings === [] ? 'clean' : count($sqlFindings) . ' finding(s)';
    check('hand-written SQL quotes reserved words', $sqlFindings === [],
        'Run php tools/sql-audit.php for the list — MySQL/MariaDB reserved words '
        . '(condition, rank, system …) must be wrapped in backticks.', false, $detail);
    foreach (array_slice($sqlFindings, 0, 5) as $finding) {
        echo '    ' . $finding['file'] . ':' . $finding['line'] . ' → ' . $finding['word'] . "\n";
    }
} else {
    check('tools/sql-audit.php present', false,
        'Upload tools/sql-audit.php so reserved-word mistakes can be caught before going live.', false);
}

/* ------------------------------------------------------------------ release */
echo "\nRelease files\n";
check('PHP 8.1 or newer', PHP_VERSION_ID >= 80100,
    'Switch the PHP version in the hosting control panel — the app uses 8.1 language features.');

foreach ([
    'favicon.ico'                     => 'Health-check tools and browsers ask for /favicon.ico directly.',
    'manifest.webmanifest'            => 'Adds the installable-app metadata and the home-screen icon.',
    'maintenance.php'                 => 'Public holding page (also used by the preview link in settings).',
    'includes/maintenance.php'        => 'Renderer behind Settings → Commerce → Maintenance mode.',
    'assets/js/media-url.js'          => 'Shared Drive/URL normaliser for every image field.',
    'assets/img/og-cover.png'         => 'Fallback social share image (1200×630).',
    'assets/img/apple-touch-icon.png' => 'iOS home-screen icon.',
    'assets/img/favicon-32.png'       => 'PNG favicon used when no custom favicon is set.',
] as $file => $why) {
    check("file: {$file}", is_file(ROOT_PATH . '/' . $file), "Upload {$file} — {$why}");
}

$headerFile = ROOT_PATH . '/includes/layout/header.php';
$headerSource = is_file($headerFile) ? (string) file_get_contents($headerFile) : '';
echo "\nStorefront metadata (the health-check items)\n";
foreach ([
    'viewport meta'        => 'name="viewport"',
    'canonical link'       => 'rel="canonical"',
    'meta description'     => 'name="description"',
    'Open Graph tags'      => 'property="og:image"',
    'Twitter card tags'    => 'name="twitter:card"',
    'JSON-LD structured data' => 'application/ld+json',
    'favicon links'        => 'rel="icon"',
    'apple touch icon'     => 'rel="apple-touch-icon"',
    'web manifest link'    => 'rel="manifest"',
] as $label => $needle) {
    check("header ships: {$label}", str_contains($headerSource, $needle),
        'includes/layout/header.php looks out of date — upload the current release.');
}

$footerSource = is_file(ROOT_PATH . '/includes/layout/footer.php')
    ? (string) file_get_contents(ROOT_PATH . '/includes/layout/footer.php')
    : '';
foreach ([
    'navigation links'   => '/shop.php',
    'privacy policy'     => '/privacy.php',
    'terms of service'   => '/terms.php',
    'copyright notice'   => 'date(\'Y\')',
    'contact details'    => 'support_phone',
    'social links'       => 'social-link',
    'support / chat dock'=> 'data-live-chat',
    'enquiry form'       => 'newsletter-form',
] as $label => $needle) {
    check("footer ships: {$label}", str_contains($footerSource, $needle),
        'includes/layout/footer.php looks out of date — upload the current release.');
}

$homeSource = is_file(ROOT_PATH . '/index.php') ? (string) file_get_contents(ROOT_PATH . '/index.php') : '';
check('home page ships a contact form', str_contains($homeSource, 'data-contact-form'),
    'index.php is out of date — upload the current release.');
check('home page ships social proof', str_contains($homeSource, 'trust-strip'),
    'index.php is out of date — upload the current release.');

/* --------------------------------------------------------------------- logs */
echo "\nRecent errors\n";
$logFiles = glob(STORAGE_PATH . '/logs/error-*.log') ?: [];
if ($logFiles === []) {
    check('no error log yet', true, '', true, 'clean');
} else {
    sort($logFiles);
    $latest = (string) end($logFiles);
    $lines = array_slice(array_filter(explode("\n", (string) file_get_contents($latest))), -6);
    check('error log is empty today', $lines === [],
        'See ' . basename($latest) . " — the newest entries are printed above.", false,
        count($lines) . ' line(s)');
    if ($lines !== []) {
        echo "  --- " . basename($latest) . " ---\n";
        foreach ($lines as $line) {
            echo '  ' . substr($line, 0, 160) . "\n";
        }
    }
}

echo "\n" . str_repeat('-', 70) . "\n";
if ($blocking === 0) {
    printf("\033[32mReady.\033[0m %d warning(s).\n", $warnings);
    echo "Sign in at /login.php and change the seeded administrator password.\n";
    exit(0);
}
printf("\033[31m%d blocking problem(s)\033[0m and %d warning(s).\n", $blocking, $warnings);
exit(1);

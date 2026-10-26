<?php
declare(strict_types=1);

/**
 * SoftMyMarket bootstrap.
 *
 * Every public entry point starts here:
 *   require __DIR__ . '/includes/bootstrap.php';
 *
 * Responsibilities
 *   1. define paths and autoloading            4. hardening + security headers
 *   2. load configuration                      5. request/session bootstrap
 *   3. error + exception handling              6. CSRF token bootstrap
 */

// Guard against a second include (a page that pulls bootstrap twice, or a
// partial that bootstraps on its own). Without this PHP fatals on the
// constant redefinition — cheaper to make the file idempotent.
if (defined('SMM_BOOTSTRAPPED')) {
    return;
}
define('SMM_BOOTSTRAPPED', true);

define('SMM_START', microtime(true));
define('SMM_VERSION', '1.0.9');
define('ROOT_PATH', str_replace('\\', '/', dirname(__DIR__)));
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('IS_CLI', PHP_SAPI === 'cli');

/** ---------------------------------------------------------------- autoload */
spl_autoload_register(static function (string $class): void {
    $file = INCLUDES_PATH . '/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

/** ------------------------------------------------------------- configuration */
$configFile = ROOT_PATH . '/config/config.php';
if (!is_file($configFile)) {
    if (!IS_CLI && !str_starts_with((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/install')) {
        // Nothing works without a database. Explain instead of redirecting: on
        // hosts where install/ is blocked by .htaccess a redirect would loop.
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        $hasInstaller = is_file(ROOT_PATH . '/install/index.php');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>SoftMyMarket is not configured</title>'
            . '<link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css"></head>'
            . '<body class="bg-light d-flex align-items-center min-vh-100"><div class="container" style="max-width:640px">'
            . '<div class="card shadow-sm"><div class="card-body p-4">'
            . '<h1 class="h4 fw-bold mb-3">SoftMyMarket is not configured yet</h1>'
            . '<p class="text-secondary">The application could not find <code>config/config.php</code>, '
            . 'which holds the database credentials.</p>'
            . '<ol class="small text-secondary">'
            . '<li>Copy <code>config/config.example.php</code> to <code>config/config.php</code>.</li>'
            . '<li>Fill in <code>db.host</code>, <code>db.name</code>, <code>db.user</code>, <code>db.pass</code> '
            . 'and a 64-character <code>app.key</code>.</li>'
            . '<li>Import <code>database/install.sql</code> in phpMyAdmin.</li>'
            . ($hasInstaller ? '<li>Or run the guided installer at <a href="/install/index.php">/install/</a>.</li>' : '')
            . '</ol>'
            . '<p class="small text-secondary mb-0">Full instructions: <code>docs/AEONFREE.md</code>.</p>'
            . '</div></div></div></body></html>';
        exit;
    }
    // The installer needs the defaults to render its own form.
    $configFile = ROOT_PATH . '/config/config.example.php';
}

/** @var array<string,mixed> $config */
$config = require $configFile;

// Environment overrides (SMM_DB_HOST=... SMM_APP_URL=... SMM_APP_DEBUG=1)
$envMap = [
    'SMM_APP_URL'      => 'app.url',
    'SMM_APP_ENV'      => 'app.env',
    'SMM_APP_DEBUG'    => 'app.debug',
    'SMM_APP_KEY'      => 'app.key',
    'SMM_DB_HOST'      => 'db.host',
    'SMM_DB_PORT'      => 'db.port',
    'SMM_DB_NAME'      => 'db.name',
    'SMM_DB_USER'      => 'db.user',
    'SMM_DB_PASS'      => 'db.pass',
    'SMM_DB_PREFIX'    => 'db.prefix',
    'SMM_SESSION_SECURE' => 'session.secure',
];
foreach ($envMap as $env => $path) {
    $value = getenv($env);
    if ($value === false || $value === '') {
        continue;
    }
    $segments = explode('.', $path);
    $ref = &$config;
    foreach ($segments as $segment) {
        $ref = &$ref[$segment];
    }
    $ref = in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true) ? true
        : (in_array(strtolower((string) $value), ['0', 'false', 'off', 'no'], true) ? false : $value);
    unset($ref);
}
Config::load($config);
unset($config, $envMap, $env, $path, $value, $segments);

date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Colombo'));
setlocale(LC_MONETARY, 'en_LK.UTF-8', 'en_US.UTF-8', 'C');

/**
 * app.key signs password-reset links and guest order-tracking links, so a
 * placeholder or a too-short key is a real vulnerability. Rather than dying
 * with an opaque 500 (which is impossible to diagnose on free hosting), show
 * exactly which line of config/config.php to fix.
 */
$appKey = (string) Config::get('app.key', '');
$keyBroken = strlen($appKey) < 32 || stripos($appKey, 'CHANGE-ME') !== false;
$runningInstaller = str_starts_with((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/install');
if ($keyBroken && !IS_CLI && !$runningInstaller) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>SoftMyMarket configuration needs attention</title>'
        . '<link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css"></head>'
        . '<body class="bg-light d-flex align-items-center min-vh-100"><div class="container" style="max-width:680px">'
        . '<div class="card shadow-sm"><div class="card-body p-4">'
        . '<h1 class="h4 fw-bold mb-3">One setting is missing: <code>app.key</code></h1>'
        . '<p class="text-secondary mb-2">SoftMyMarket is installed and reachable, but '
        . '<code>config/config.php</code> still has a placeholder secret. It signs password-reset '
        . 'and order-tracking links, so it has to be random and private.</p>'
        . '<ol class="small text-secondary">'
        . '<li>Generate a key:<br><code>php -r "echo bin2hex(random_bytes(32));"</code></li>'
        . '<li>Paste the 64-character result into <code>config/config.php</code> at '
        . '<code>\'key\' =&gt; \'...\'</code>.</li>'
        . '<li>Save the file and reload this page.</li>'
        . '</ol>'
        . '<p class="small text-secondary mb-0">Everything else — the database, the catalogue and '
        . 'the dashboard — is already working.</p>'
        . '</div></div></div></body></html>';
    exit;
}
if (!$keyBroken && !preg_match('/^[0-9a-f]{64}$/i', $appKey)) {
    // Works, but is not the documented format: warn instead of breaking the site.
    error_log('[smm] app.key should be 64 hex characters (bin2hex(random_bytes(32))); the configured key is ' . strlen($appKey) . ' characters.');
}
unset($appKey, $keyBroken, $runningInstaller);

/** ------------------------------------------------------- error + exception */
error_reporting(E_ALL);
ini_set('display_errors', Config::get('app.debug') ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');
ini_set('expose_php', '0');

/**
 * Error handling policy.
 *
 * Notices, warnings and deprecations are logged, never fatal — on shared
 * hosting a single PHP deprecation (or an undefined array key in a template)
 * must not take the whole storefront down. With app.debug on they are thrown so
 * development mistakes are impossible to miss.
 *
 * Recoverable and user errors always throw: those mean the code is wrong.
 */
set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false; // silenced with @, or excluded by error_reporting()
    }

    // E_STRICT is intentionally absent: the constant itself is deprecated in
    // PHP 8.4 and referencing it here would raise a deprecation on every call.
    $soft = [
        E_WARNING, E_NOTICE, E_DEPRECATED,
        E_USER_WARNING, E_USER_NOTICE, E_USER_DEPRECATED,
    ];
    if (!in_array($severity, $soft, true) || Config::get('app.debug')) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    error_log(sprintf(
        '[%s] %s: %s in %s:%d',
        date('c'),
        match ($severity) {
            E_WARNING, E_USER_WARNING => 'Warning',
            E_NOTICE, E_USER_NOTICE => 'Notice',
            E_DEPRECATED, E_USER_DEPRECATED => 'Deprecated',
            default => 'PHP',
        },
        $message,
        $file,
        $line
    ));
    return true;
});

/**
 * Last-resort page for an uncaught exception.
 *
 * Kept as a named function (rather than a closure) so tests and
 * tools/deploy-check.php can exercise it directly. Whatever breaks, the visitor
 * must get a readable page carrying a reference id that also exists in
 * storage/logs/, so support can find the exact stack trace.
 */
if (!function_exists('smm_render_fatal')) {
    function smm_render_fatal(Throwable $e): void
    {
        $reference = 'SMM-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $entry = sprintf(
            "[%s] %s %s: %s in %s:%d\n%s\n",
            date('c'),
            $reference,
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
        error_log($entry);
        // error_log() can be swallowed by the host (many free hosts point it at a
        // file we cannot read), so always append to our own dated log as well.
        // Logging must never mask the original error.
        try {
            $logDir = STORAGE_PATH . '/logs';
            if (is_dir($logDir) || @mkdir($logDir, 0775, true)) {
                @file_put_contents($logDir . '/error-' . date('Y-m-d') . '.log', $entry, FILE_APPEND | LOCK_EX);
            }
        } catch (Throwable $ignored) {
            // ignore
        }

        if (IS_CLI) {
            fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
            exit(1);
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }

        $debug = (bool) Config::get('app.debug');
        $safeMessage = $debug
            ? htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
            : 'Something went wrong on our side.';
        $safeTrace = $debug
            ? '<pre class="small text-start overflow-auto">' . htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8') . '</pre>'
            : '';
        $safeRef = htmlspecialchars($reference, ENT_QUOTES, 'UTF-8');

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Server error — ' . htmlspecialchars((string) Config::get('app.name', 'SoftMyMarket'), ENT_QUOTES, 'UTF-8') . '</title>';
        echo '<meta name="robots" content="noindex, nofollow">';
        echo '<link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css"></head>';
        echo '<body class="bg-light d-flex align-items-center justify-content-center min-vh-100"><div class="text-center p-4">';
        echo '<div class="display-5 mb-3">⚠️</div><h1 class="h3 fw-bold">We hit a snag</h1>';
        echo '<p class="text-secondary">' . $safeMessage . '</p>';
        echo '<a class="btn btn-success mt-2" href="/">Back to the store</a>';
        echo '<p class="text-secondary small mt-3 mb-0">Reference: <code>' . $safeRef . '</code><br>';
        echo 'Quote this reference to support — the matching entry is in <code>storage/logs/</code>.</p>';
        echo $safeTrace;
        echo '</div></body></html>';
    }
}

set_exception_handler('smm_render_fatal');

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    error_log(sprintf('[%s] FATAL %s in %s:%d', date('c'), $error['message'], $error['file'], $error['line']));
});

/** ---------------------------------------------------------------- security */
require_once INCLUDES_PATH . '/helpers.php';
require_once INCLUDES_PATH . '/Csrf.php';
require_once INCLUDES_PATH . '/Auth.php';
require_once INCLUDES_PATH . '/Database.php';
require_once INCLUDES_PATH . '/Settings.php';

if (!IS_CLI) {
    Security::sendHeaders();
    Session::start();
    Auth::boot();
    // Every POST is CSRF-checked here; gateway callbacks and the installer are
    // exempt because they authenticate their callers differently.
    enforce_csrf();
}

/**
 * Maintenance mode gate.
 *
 * Settings → Commerce → Maintenance mode swaps the storefront for a holding
 * page. Runs after the session and Auth boot so the team keeps full access to
 * the dashboard (and can preview the storefront while it is hidden).
 *
 * Exempt: the dashboard, login/logout, assets, the image proxy, payment
 * callbacks, the installer, the deploy check and the holding page itself.
 */
if (!IS_CLI && !defined('SMM_MAINTENANCE_PAGE')) {
    try {
        $maintenanceOn = Settings::bool('maintenance_mode');
    } catch (Throwable $e) {
        $maintenanceOn = false; // never hide the store because a setting failed to load
    }
    if ($maintenanceOn) {
        // Guarded: a partially uploaded release must not take the store down.
        if (is_file(INCLUDES_PATH . '/maintenance.php')) {
            require_once INCLUDES_PATH . '/maintenance.php';
        }
        $path = current_path();
        if (is_file(INCLUDES_PATH . '/maintenance.php') && function_exists('smm_maintenance_page')
            && !smm_maintenance_exempt($path) && !Auth::isTeam()) {
            // JSON callers (cart, wishlist, live search) get a usable answer
            // instead of an HTML holding page.
            if (wants_json()) {
                json_response(['error' => 'The store is briefly closed for maintenance. Please try again shortly.'], 503);
            }
            smm_maintenance_page();
        }
    }
}

/**
 * Payment credentials edited in the dashboard override config/config.php.
 * Stored as one JSON document so a save is a single atomic row write.
 */
if (is_file(ROOT_PATH . '/config/config.php')) {
    try {
        $gatewayOverrides = json_decode(Settings::get('gateway_config', ''), true);
        if (is_array($gatewayOverrides)) {
            foreach ($gatewayOverrides as $gatewayKey => $gatewayValues) {
                if (!is_array($gatewayValues)) {
                    continue;
                }
                foreach ($gatewayValues as $optionKey => $optionValue) {
                    Config::set('payments.' . $gatewayKey . '.' . $optionKey, $optionValue);
                }
            }
        }
        unset($gatewayOverrides, $gatewayKey, $gatewayValues, $optionKey, $optionValue);
    } catch (Throwable $e) {
        error_log('Gateway settings load failed: ' . $e->getMessage());
    }
}

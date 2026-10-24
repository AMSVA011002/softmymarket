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

define('SMM_START', microtime(true));
define('SMM_VERSION', '1.0.0');
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
    if (!IS_CLI && !str_starts_with($_SERVER['SCRIPT_NAME'] ?? '', '/install')) {
        header('Location: /install/index.php', true, 302);
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

/** ------------------------------------------------------- error + exception */
error_reporting(E_ALL);
ini_set('display_errors', Config::get('app.debug') ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');
ini_set('expose_php', '0');

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    error_log(sprintf(
        "[%s] %s: %s in %s:%d\n%s",
        date('c'),
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    ));

    if (IS_CLI) {
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
        exit(1);
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $debug = (bool) Config::get('app.debug');
    $safeMessage = $debug ? htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') : 'Something went wrong on our side.';
    $safeTrace = $debug ? '<pre class="small text-start overflow-auto">' . htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8') . '</pre>' : '';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Server error — ' . htmlspecialchars((string) Config::get('app.name', 'SoftMyMarket'), ENT_QUOTES, 'UTF-8') . '</title>';
    echo '<link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css"></head>';
    echo '<body class="bg-light d-flex align-items-center justify-content-center min-vh-100"><div class="text-center p-4">';
    echo '<div class="display-5 mb-3">⚠️</div><h1 class="h3 fw-bold">We hit a snag</h1>';
    echo '<p class="text-secondary">' . $safeMessage . '</p>';
    echo '<a class="btn btn-success mt-2" href="/">Back to the store</a>';
    echo '<p class="text-secondary small mt-3 mb-0">Reference: ' . htmlspecialchars(date('Ymd-His'), ENT_QUOTES, 'UTF-8') . '</p>';
    echo $safeTrace;
    echo '</div></body></html>';
});

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

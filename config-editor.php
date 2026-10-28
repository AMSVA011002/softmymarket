<?php
declare(strict_types=1);

/**
 * Temporary, standalone config/config.php editor for shared hosting.
 *
 * Security: HTTPS + current app.key + CSRF + session + per-IP throttling.
 * The endpoint is permanently disabled after an administrator successfully
 * signs in (config/config-editor.lock). It intentionally does not bootstrap
 * the application or connect to MySQL, so it can repair database settings.
 */
$root = __DIR__;
$editorUrl = '/' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'config-editor.php'));
$configPath = $root . '/config/config.php';
$lockPath = $root . '/config/config-editor.lock';
$rateFile = $root . '/storage/config-editor-attempts.json';

http_response_code(200);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Content-Type: text/html; charset=utf-8');

$https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https';
if (!$https) {
    http_response_code(400);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>HTTPS required</title><h1>HTTPS required</h1><p>Open this temporary configuration page using its HTTPS URL. Do not submit passwords over plain HTTP.</p></html>';
    exit;
}

if (is_file($lockPath)) {
    http_response_code(404);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Not found</title><h1>Not found</h1></html>';
    exit;
}

if (!is_file($configPath)) {
    http_response_code(503);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Configuration unavailable</title><h1>config/config.php was not found</h1><p>Create it from config/config.example.php or use the guided installer first.</p></html>';
    exit;
}

try {
    $config = require $configPath;
} catch (Throwable) {
    http_response_code(503);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Configuration could not be read</title><h1>Configuration could not be read</h1><p>The existing PHP config has a syntax or runtime error and could not be loaded. Restore a valid config file using your hosting file manager, then retry.</p></html>';
    exit;
}
if (!is_array($config)) {
    http_response_code(503);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Configuration unavailable</title><h1>config/config.php must return an array.</h1></html>';
    exit;
}

$currentKey = (string) ($config['app']['key'] ?? '');
if (strlen($currentKey) < 32 || stripos($currentKey, 'CHANGE-ME') !== false) {
    http_response_code(503);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Recovery key unavailable</title><h1>The current app.key cannot protect this page.</h1><p>Set a private, random app.key of at least 32 characters in config/config.php first. The example placeholder is deliberately rejected because it is public.</p></html>';
    exit;
}

session_name('yourmarket_config_editor');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_start();
if (empty($_SESSION['config_editor_csrf'])) {
    $_SESSION['config_editor_csrf'] = bin2hex(random_bytes(32));
}
$_SESSION['config_editor_last_activity'] = (int) ($_SESSION['config_editor_last_activity'] ?? time());
if (time() - $_SESSION['config_editor_last_activity'] > 900) {
    unset($_SESSION['config_editor_authenticated']);
}
$_SESSION['config_editor_last_activity'] = time();

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$errors = [];
$notice = '';

/** Fixed, private-IP-hashed, 15-minute lockout for app.key guesses. */
$rateCheck = static function (bool $failed = false, bool $success = false) use ($rateFile, $currentKey): array {
    $handle = @fopen($rateFile, 'c+');
    if ($handle === false) {
        return ['ok' => false, 'retry' => 900];
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return ['ok' => false, 'retry' => 900];
    }
    $contents = stream_get_contents($handle);
    $records = is_string($contents) ? json_decode($contents, true) : null;
    if (!is_array($records)) {
        $records = [];
    }
    $ipKey = hash_hmac('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), $currentKey);
    $now = time();
    $attempts = array_values(array_filter((array) ($records[$ipKey] ?? []), static fn ($time): bool => is_int($time) && $time > $now - 900));
    $retry = $attempts !== [] ? max(0, 900 - ($now - min($attempts))) : 0;
    if ($success) {
        unset($records[$ipKey]);
    } elseif ($failed) {
        $attempts[] = $now;
        $records[$ipKey] = $attempts;
    }
    $blocked = count($attempts) >= 5 && !$success;
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, (string) json_encode($records, JSON_UNESCAPED_SLASHES));
    fflush($handle);
    @chmod($rateFile, 0600);
    flock($handle, LOCK_UN);
    fclose($handle);
    return ['ok' => !$blocked, 'retry' => $retry];
};

$csrfValid = static function () use ($escape): bool {
    $submitted = (string) ($_POST['_csrf'] ?? '');
    $expected = (string) ($_SESSION['config_editor_csrf'] ?? '');
    if ($submitted !== '' && $expected !== '' && hash_equals($expected, $submitted)) {
        return true;
    }
    http_response_code(400);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Request expired</title><h1>Request expired</h1><p>Reload the page and try again.</p></html>';
    return false;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$csrfValid()) {
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'unlock' && empty($_SESSION['config_editor_authenticated'])) {
        $rate = $rateCheck();
        if (!$rate['ok']) {
            $errors[] = 'Too many unsuccessful attempts. Wait 15 minutes and try again.';
        } elseif (hash_equals($currentKey, trim((string) ($_POST['unlock_key'] ?? '')))) {
            $rateCheck(false, true);
            session_regenerate_id(true);
            $_SESSION['config_editor_authenticated'] = true;
            $_SESSION['config_editor_csrf'] = bin2hex(random_bytes(32));
            header('Location: ' . $editorUrl, true, 303);
            exit;
        } else {
            $rateCheck(true, false);
            $errors[] = 'The app.key did not match. No configuration values were changed.';
        }
    } elseif ($action === 'save' && !empty($_SESSION['config_editor_authenticated'])) {
        $posted = $_POST['values'] ?? [];
        if (!is_array($posted)) {
            $posted = [];
        }
        $updated = $config;
        $read = static function (string $group, string $key) use ($posted): string {
            $parts = explode('.', $group);
            $value = $posted;
            foreach ($parts as $part) {
                $value = is_array($value) ? ($value[$part] ?? null) : null;
            }
            $value = is_array($value) ? ($value[$key] ?? '') : '';
            return is_scalar($value) ? trim((string) $value) : '';
        };
        $put = static function (array &$target, array $path, mixed $value): void {
            $last = array_pop($path);
            if ($last === null) {
                return;
            }
            $ref = &$target;
            foreach ($path as $part) {
                if (!isset($ref[$part]) || !is_array($ref[$part])) {
                    $ref[$part] = [];
                }
                $ref = &$ref[$part];
            }
            $ref[$last] = $value;
            unset($ref);
        };
        $setString = static function (string $group, string $key, int $max = 500) use (&$updated, $read, &$errors, $put): void {
            $value = $read($group, $key);
            if (strlen($value) > $max) {
                $errors[] = ucfirst(str_replace('_', ' ', $key)) . ' is too long.';
                return;
            }
            $put($updated, array_merge(explode('.', $group), [$key]), $value);
        };
        $setInt = static function (string $group, string $key, int $min, int $max) use (&$updated, $read, &$errors, $put): void {
            $value = $read($group, $key);
            if ($value === '' || !ctype_digit($value) || (int) $value < $min || (int) $value > $max) {
                $errors[] = ucfirst(str_replace('_', ' ', $key)) . " must be a whole number from {$min} to {$max}.";
                return;
            }
            $put($updated, array_merge(explode('.', $group), [$key]), (int) $value);
        };

        foreach ([
            ['app', 'name', 120], ['app', 'tagline', 200], ['app', 'url', 500],
            ['app', 'env', 20], ['app', 'timezone', 100], ['app', 'locale', 40],
            ['app', 'currency', 3], ['app', 'currency_symbol', 12], ['app', 'support_email', 160],
            ['app', 'support_phone', 40],
            ['db', 'host', 255], ['db', 'name', 128], ['db', 'user', 128],
            ['db', 'charset', 20], ['db', 'prefix', 32],
            ['session', 'name', 80], ['session', 'samesite', 10], ['session', 'domain', 255],
            ['mail', 'transport', 20], ['mail', 'from_email', 160], ['mail', 'from_name', 120], ['mail', 'reply_to', 160],
            ['images', 'cache_dir', 255], ['images', 'placeholder', 255],
        ] as [$group, $key, $max]) {
            $setString($group, $key, $max);
        }
        foreach (['host' => 255, 'encryption' => 10, 'username' => 160] as $smtpKey => $smtpMax) {
            $setString('mail.smtp', $smtpKey, $smtpMax);
        }

        $url = $read('app', 'url');
        $urlParts = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !is_array($urlParts)
            || !in_array(strtolower((string) ($urlParts['scheme'] ?? '')), ['https', 'http'], true)
            || empty($urlParts['host']) || isset($urlParts['query']) || isset($urlParts['fragment'])
            || str_ends_with($url, '/')) {
            $errors[] = 'Public app URL must be a complete http(s) URL with no trailing slash, query or fragment.';
        }
        if (!in_array($read('app', 'env'), ['production', 'local'], true)) {
            $errors[] = 'Environment must be production or local.';
        }
        try {
            new DateTimeZone($read('app', 'timezone'));
        } catch (Throwable) {
            $errors[] = 'Choose a valid PHP timezone, such as Asia/Colombo or UTC.';
        }
        if (!preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/D', $read('app', 'locale'))) {
            $errors[] = 'Locale must be a language tag such as en-LK or si-LK.';
        }
        if (!preg_match('/^[A-Za-z]{3}$/D', $read('app', 'currency'))) {
            $errors[] = 'Currency must be a three-letter code such as LKR or USD.';
        }
        foreach (['support_email' => 'app', 'from_email' => 'mail', 'reply_to' => 'mail'] as $key => $group) {
            $value = $read($group, $key);
            if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[] = ucfirst(str_replace('_', ' ', $key)) . ' must be a valid email address.';
            }
        }
        if (!in_array($read('db', 'charset'), ['utf8mb4', 'utf8'], true)) {
            $errors[] = 'Database charset must be utf8mb4 or utf8.';
        }
        if ($read('db', 'host') === '' || $read('db', 'name') === '' || $read('db', 'user') === '') {
            $errors[] = 'Database host, database name and database username are required.';
        }
        if (!in_array($read('session', 'samesite'), ['Lax', 'Strict', 'None'], true)) {
            $errors[] = 'Session SameSite must be Lax, Strict or None.';
        }
        if ($read('session', 'samesite') === 'None' && empty($posted['session']['secure'])) {
            $errors[] = 'SameSite=None requires secure session cookies.';
        }
        if (!in_array($read('mail', 'transport'), ['smtp', 'mail'], true)) {
            $errors[] = 'Mail transport must be smtp or mail.';
        }
        if (!in_array($read('mail.smtp', 'encryption'), ['ssl', 'tls'], true)) {
            $errors[] = 'SMTP security must be ssl or tls.';
        }
        foreach ([
            ['db', 'port', 1, 65535], ['app', 'orders_per_page', 1, 100], ['app', 'products_per_page', 1, 100],
            ['session', 'lifetime', 300, 2592000], ['session', 'idle', 300, 86400],
            ['security', 'max_login_attempts', 1, 100], ['security', 'lockout_minutes', 1, 1440],
            ['security', 'max_order_qty', 1, 10000], ['security', 'session_regenerate', 60, 86400],
            ['mail.smtp', 'port', 1, 65535], ['mail.smtp', 'timeout', 5, 60],
            ['images', 'cache_ttl', 60, 31536000], ['images', 'max_bytes', 1024, 20971520],
        ] as [$group, $key, $min, $max]) {
            $setInt($group, $key, $min, $max);
        }

        $newKey = $read('app', 'key');
        if ($newKey !== '') {
            if (strlen($newKey) < 32 || stripos($newKey, 'CHANGE-ME') !== false) {
                $errors[] = 'New app.key must be a private random secret of at least 32 characters.';
            } else {
                $put($updated, ['app', 'key'], $newKey);
            }
        }
        $dbPass = $read('db', 'pass');
        if ($dbPass !== '') {
            if (strlen($dbPass) > 512) {
                $errors[] = 'Database password is too long.';
            } else {
                $put($updated, ['db', 'pass'], $dbPass);
            }
        } elseif (!empty($posted['db']['clear_pass'])) {
            $put($updated, ['db', 'pass'], '');
        }

        foreach ([
            ['app', 'debug'], ['session', 'secure'], ['mail', 'log'],
            ['security', 'trusted_proxies'], ['images', 'allow_public_hosts'], ['images', 'proxy_remote'],
        ] as [$group, $key]) {
            if (in_array($key, ['trusted_proxies'], true)) {
                $raw = $posted[$group][$key] ?? '';
                $items = preg_split('/[\\r\\n,]+/', is_scalar($raw) ? (string) $raw : '') ?: [];
                $items = array_values(array_unique(array_filter(array_map('trim', $items), static fn (string $value): bool => $value !== '')));
                foreach ($items as $item) {
                    if (!filter_var($item, FILTER_VALIDATE_IP)) {
                        $errors[] = 'Trusted proxies must be valid IP addresses, one per line.';
                        break;
                    }
                }
                $put($updated, [$group, $key], $items);
            } else {
                $put($updated, [$group, $key], !empty($posted[$group][$key]));
            }
        }
        foreach ([['images', 'allowed_hosts']] as [$group, $key]) {
            $raw = $posted[$group][$key] ?? '';
            $items = preg_split('/[\\r\\n,]+/', is_scalar($raw) ? (string) $raw : '') ?: [];
            $items = array_values(array_unique(array_filter(array_map('strtolower', array_map('trim', $items)), static fn (string $value): bool => $value !== '')));
            foreach ($items as $host) {
                if (strlen($host) > 253 || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/D', $host)) {
                    $errors[] = 'Image allow-list entries must be hostnames, one per line.';
                    break;
                }
            }
            $put($updated, [$group, $key], $items);
        }
        $put($updated, ['mail', 'smtp', 'port'], (int) $read('mail.smtp', 'port'));
        $put($updated, ['mail', 'smtp', 'timeout'], (int) $read('mail.smtp', 'timeout'));

        if ($errors === []) {
            $source = "<?php\ndeclare(strict_types=1);\n\n/** Updated via the protected temporary config editor. */\nreturn " . var_export($updated, true) . ";\n";
            $temporaryPath = $configPath . '.tmp-' . bin2hex(random_bytes(8));
            $permissions = @fileperms($configPath);
            $mode = $permissions !== false ? ($permissions & 0777) : 0640;
            if (@file_put_contents($temporaryPath, $source, LOCK_EX) === false) {
                $errors[] = 'Could not write a temporary config file. Check config/ directory permissions.';
            } else {
                @chmod($temporaryPath, $mode);
                if (!@rename($temporaryPath, $configPath)) {
                    @unlink($temporaryPath);
                    $errors[] = 'Could not replace config/config.php. Check config/ directory permissions.';
                } else {
                    @chmod($configPath, $mode);
                    $config = $updated;
                    $currentKey = (string) ($config['app']['key'] ?? $currentKey);
                    $notice = 'Configuration saved. Test the storefront, then sign in as an administrator. A successful administrator login permanently locks this editor.';
                }
            }
        }
    }
}

$authenticated = !empty($_SESSION['config_editor_authenticated']);
$csrf = (string) $_SESSION['config_editor_csrf'];
$get = static function (array $array, string $group, string $key, mixed $default = ''): mixed {
    $value = $array;
    foreach (explode('.', $group) as $part) {
        $value = is_array($value) ? ($value[$part] ?? null) : null;
    }
    return is_array($value) ? ($value[$key] ?? $default) : $default;
};
$field = static function (string $group, string $key, string $label, string $type = 'text', mixed $default = null) use ($config, $get, $escape): string {
    $value = $get($config, $group, $key, $default ?? '');
    $id = 'f-' . preg_replace('/[^a-z0-9-]/i', '-', $group . '-' . $key);
    $name = 'values';
    foreach (explode('.', $group) as $part) {
        $name .= '[' . $part . ']';
    }
    $name .= '[' . $key . ']';
    if ($type === 'bool') {
        return '<div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="' . $escape($id) . '" name="' . $escape($name) . '" value="1"' . (!empty($value) ? ' checked' : '') . '><label class="form-check-label" for="' . $escape($id) . '">' . $escape($label) . '</label></div>';
    }
    if ($type === 'textarea') {
        $shown = is_array($value) ? implode("\n", $value) : (string) $value;
        return '<div class="mb-3"><label class="form-label" for="' . $escape($id) . '">' . $escape($label) . '</label><textarea class="form-control" id="' . $escape($id) . '" name="' . $escape($name) . '" rows="3">' . $escape($shown) . '</textarea></div>';
    }
    if ($type === 'password') {
        return '<div class="mb-3"><label class="form-label" for="' . $escape($id) . '">' . $escape($label) . '</label><input class="form-control" id="' . $escape($id) . '" type="password" name="' . $escape($name) . '" value="" autocomplete="new-password" placeholder="Saved — leave blank to keep current"><div class="form-text">The saved secret is never displayed.</div></div>';
    }
    $inputType = $type === 'int' ? 'number' : $type;
    $shown = $type === 'int' ? ' value="' . $escape($value) . '" step="1"' : ' value="' . $escape($value) . '"';
    return '<div class="mb-3"><label class="form-label" for="' . $escape($id) . '">' . $escape($label) . '</label><input class="form-control" id="' . $escape($id) . '" type="' . $escape($inputType) . '" name="' . $escape($name) . '"' . $shown . '></div>';
};
$select = static function (string $group, string $key, string $label, array $choices) use ($config, $get, $escape): string {
    $value = (string) $get($config, $group, $key, '');
    $id = 'f-' . preg_replace('/[^a-z0-9-]/i', '-', $group . '-' . $key);
    $name = 'values';
    foreach (explode('.', $group) as $part) {
        $name .= '[' . $part . ']';
    }
    $name .= '[' . $key . ']';
    $options = '';
    foreach ($choices as $choice => $caption) {
        $options .= '<option value="' . $escape($choice) . '"' . ($value === (string) $choice ? ' selected' : '') . '>' . $escape($caption) . '</option>';
    }
    return '<div class="mb-3"><label class="form-label" for="' . $escape($id) . '">' . $escape($label) . '</label><select class="form-select" id="' . $escape($id) . '" name="' . $escape($name) . '">' . $options . '</select></div>';
};
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Temporary configuration editor</title>
  <link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css">
  <style>body{background:#f4f7f5}.config-editor{max-width:960px;margin:2rem auto;padding:1rem}.config-card{background:#fff;border:1px solid #dbe4dc;border-radius:1rem;padding:1.25rem;margin-bottom:1rem}.secret-note{font-size:.875rem;color:#52605a}</style>
</head>
<body>
<main class="config-editor">
  <header class="mb-3"><h1 class="h3 fw-bold">Temporary YourMarket configuration editor</h1><p class="text-secondary mb-0">This page writes <code>config/config.php</code> directly and does not connect to MySQL. Use only over HTTPS.</p></header>
  <?php if ($notice !== ''): ?><div class="alert alert-success"><?= $escape($notice) ?></div><?php endif; ?>
  <?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= $escape($error) ?></div><?php endforeach; ?>
  <?php if (!$authenticated): ?>
    <section class="config-card">
      <h2 class="h5">Unlock this temporary page</h2>
      <p class="secret-note">Enter the current private <code>app.key</code> from <code>config/config.php</code>. It must be at least 32 characters and must not be the public example placeholder. Five incorrect attempts from one IP trigger a 15-minute lockout.</p>
      <form method="post" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
        <input type="hidden" name="action" value="unlock">
        <label class="form-label" for="unlock-key">Current app.key</label>
        <input class="form-control mb-3" id="unlock-key" type="password" name="unlock_key" autocomplete="current-password" required autofocus>
        <button class="btn btn-success" type="submit">Unlock editor</button>
      </form>
    </section>
  <?php else: ?>
    <div class="alert alert-warning"><strong>Temporary recovery access.</strong> After an administrator successfully signs in, the editor locks itself and this URL returns 404. The app key is used to unlock this page. Payment gateway settings remain managed from the dashboard; SMTP mailbox passwords are stored securely in Admin → Settings → Email.</div>
    <form method="post" autocomplete="off">
      <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
      <input type="hidden" name="action" value="save">
      <section class="config-card">
        <h2 class="h5">Application</h2>
        <div class="row"><div class="col-md-6"><?= $field('app', 'name', 'Application / store name') ?></div><div class="col-md-6"><?= $field('app', 'tagline', 'Tagline') ?></div></div>
        <?= $field('app', 'url', 'Public URL (no trailing slash)', 'url') ?>
        <div class="row"><div class="col-md-4"><?= $select('app', 'env', 'Environment', ['production' => 'Production', 'local' => 'Local']) ?></div><div class="col-md-4"><?= $field('app', 'timezone', 'Timezone') ?></div><div class="col-md-4"><?= $field('app', 'locale', 'Locale / language tag') ?></div></div>
        <div class="row"><div class="col-md-4"><?= $field('app', 'currency', 'Currency code') ?></div><div class="col-md-4"><?= $field('app', 'currency_symbol', 'Currency symbol') ?></div><div class="col-md-4"><?= $field('app', 'support_phone', 'Support phone') ?></div></div>
        <div class="row"><div class="col-md-6"><?= $field('app', 'support_email', 'Support email', 'email') ?></div><div class="col-md-3"><?= $field('app', 'orders_per_page', 'Orders per page', 'int') ?></div><div class="col-md-3"><?= $field('app', 'products_per_page', 'Products per page', 'int') ?></div></div>
        <?= $field('app', 'key', 'Replace app.key (optional)', 'password') ?>
        <?= $field('app', 'debug', 'Enable debug mode (development only)', 'bool') ?>
      </section>
      <section class="config-card">
        <h2 class="h5">Database</h2>
        <div class="row"><div class="col-md-6"><?= $field('db', 'host', 'Database host') ?></div><div class="col-md-3"><?= $field('db', 'port', 'Port', 'int') ?></div><div class="col-md-3"><?= $field('db', 'charset', 'Charset') ?></div></div>
        <div class="row"><div class="col-md-6"><?= $field('db', 'name', 'Database name') ?></div><div class="col-md-6"><?= $field('db', 'user', 'Database user') ?></div></div>
        <?= $field('db', 'pass', 'Database password', 'password') ?>
        <label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="values[db][clear_pass]" value="1"><span class="form-check-label">Clear the database password (normally leave this unchecked)</span></label>
        <div class="row"><div class="col-md-6"><?= $field('db', 'prefix', 'Table prefix (normally blank)') ?></div></div>
      </section>
      <section class="config-card">
        <h2 class="h5">Sessions and security</h2>
        <div class="row"><div class="col-md-6"><?= $field('session', 'name', 'Session cookie name') ?></div><div class="col-md-3"><?= $field('session', 'lifetime', 'Lifetime (seconds)', 'int') ?></div><div class="col-md-3"><?= $field('session', 'idle', 'Idle timeout (seconds)', 'int') ?></div></div>
        <div class="row"><div class="col-md-6"><?= $select('session', 'samesite', 'SameSite', ['Lax' => 'Lax', 'Strict' => 'Strict', 'None' => 'None']) ?></div><div class="col-md-6"><?= $field('session', 'domain', 'Cookie domain (usually blank)') ?></div></div>
        <?= $field('session', 'secure', 'Require HTTPS for session cookies', 'bool') ?>
        <div class="row"><div class="col-md-3"><?= $field('security', 'max_login_attempts', 'Max login attempts', 'int') ?></div><div class="col-md-3"><?= $field('security', 'lockout_minutes', 'Lockout minutes', 'int') ?></div><div class="col-md-3"><?= $field('security', 'max_order_qty', 'Max order quantity', 'int') ?></div><div class="col-md-3"><?= $field('security', 'session_regenerate', 'Session rotation (seconds)', 'int') ?></div></div>
        <?= $field('security', 'trusted_proxies', 'Trusted proxy IPs (one per line)', 'textarea') ?>
      </section>
      <section class="config-card">
        <h2 class="h5">Email transport</h2>
        <div class="row"><div class="col-md-4"><?= $select('mail', 'transport', 'Transport', ['smtp' => 'Authenticated SMTP', 'mail' => 'Server mail()']) ?></div><div class="col-md-4"><?= $field('mail', 'from_email', 'From email', 'email') ?></div><div class="col-md-4"><?= $field('mail', 'from_name', 'From name') ?></div></div>
        <?= $field('mail', 'reply_to', 'Reply-to email', 'email') ?>
        <div class="row"><div class="col-md-5"><?= $field('mail.smtp', 'host', 'SMTP host') ?></div><div class="col-md-2"><?= $field('mail.smtp', 'port', 'SMTP port', 'int') ?></div><div class="col-md-2"><?= $select('mail.smtp', 'encryption', 'Security', ['ssl' => 'SSL/TLS', 'tls' => 'STARTTLS']) ?></div><div class="col-md-3"><?= $field('mail.smtp', 'timeout', 'Timeout (seconds)', 'int') ?></div></div>
        <?= $field('mail.smtp', 'username', 'SMTP username') ?>
        <p class="secret-note">SMTP password is not stored in this file by default; manage it in Admin → Settings → Email after signing in. Existing payment settings and other unlisted config values are preserved.</p>
        <?= $field('mail', 'log', 'Log outgoing email metadata', 'bool') ?>
      </section>
      <section class="config-card">
        <h2 class="h5">Image proxy</h2>
        <?= $field('images', 'allowed_hosts', 'Allowed image hosts (one hostname per line)', 'textarea') ?>
        <div class="row"><div class="col-md-4"><?= $field('images', 'cache_ttl', 'Cache lifetime (seconds)', 'int') ?></div><div class="col-md-4"><?= $field('images', 'max_bytes', 'Maximum source size (bytes)', 'int') ?></div><div class="col-md-4"><?= $field('images', 'cache_dir', 'Cache directory') ?></div></div>
        <?= $field('images', 'allow_public_hosts', 'Allow public image hosts', 'bool') ?>
        <?= $field('images', 'proxy_remote', 'Proxy external images', 'bool') ?>
        <?= $field('images', 'placeholder', 'Fallback image path') ?>
      </section>
      <div class="config-card d-flex flex-wrap gap-2 align-items-center"><button class="btn btn-success" type="submit">Validate and save config.php</button><a class="btn btn-outline-secondary" href="/login.php">Go to sign in</a><span class="secret-note">Config is written atomically with its existing file permissions.</span></div>
    </form>
  <?php endif; ?>
</main>
</body>
</html>

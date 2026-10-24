<?php
/**
 * SoftMyMarket installer.
 *
 * Three steps, each guarded by a token kept in the PHP session:
 *   1. requirements check + database details → writes config/config.php
 *   2. import database/schema.sql → creates tables
 *   3. create the administrator account → optionally seed demo data
 *
 * The installer refuses to run once storage/installed.lock exists; delete the
 * file (or re-upload a fresh copy of the folder) to reinstall.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$lockFile = STORAGE_PATH . '/installed.lock';
$configFile = ROOT_PATH . '/config/config.php';
$schemaFile = ROOT_PATH . '/database/schema.sql';
$installed = is_file($lockFile);

$errors = [];
$step = (int) ($_POST['step'] ?? $_GET['step'] ?? 1);

/** Simple escaping alias so the template stays readable. */
function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Basic requirement list for the server. */
function install_requirements(): array
{
    return [
        'PHP 8.1 or newer' => version_compare(PHP_VERSION, '8.1.0', '>='),
        'PDO with the MySQL driver' => extension_loaded('pdo_mysql'),
        'mbstring extension' => extension_loaded('mbstring'),
        'openssl extension' => extension_loaded('openssl'),
        'json extension' => extension_loaded('json'),
        'fileinfo extension' => extension_loaded('fileinfo'),
        'Writable config/ directory' => is_writable(ROOT_PATH . '/config'),
        'Writable storage/ directory' => is_writable(STORAGE_PATH),
        'Writable storage/logs/' => is_writable(STORAGE_PATH . '/logs'),
    ];
}

/** Render the config file from the example, with our values injected. */
function install_write_config(array $input): array
{
    $template = (string) file_get_contents(ROOT_PATH . '/config/config.example.php');
    $replacements = [
        "'host'    => 'localhost'," => "'host'    => '" . addslashes($input['db_host']) . "',",
        "'port'    => 3306," => "'port'    => " . (int) $input['db_port'] . ",",
        "'name'    => 'softmymarket'," => "'name'    => '" . addslashes($input['db_name']) . "',",
        "'user'    => 'softmymarket'," => "'user'    => '" . addslashes($input['db_user']) . "',",
        "'pass'    => ''," => "'pass'    => '" . addslashes($input['db_pass']) . "',",
        "'url'             => 'https://www.softmymarket.lk'," => "'url'             => '" . addslashes($input['app_url']) . "',",
        "'key'             => 'CHANGE-ME-TO-A-64-CHARACTER-RANDOM-HEX-STRING-0000000000000000'," => "'key'             => '" . bin2hex(random_bytes(32)) . "',",
        "'debug'           => false," => "'debug'           => " . (!empty($input['debug']) ? 'true' : 'false') . ",",
        "'secure'   => true," => "'secure'   => " . (!empty($input['secure_cookies']) ? 'true' : 'false') . ",",
    ];
    foreach ($replacements as $search => $replace) {
        $template = str_replace($search, $replace, $template);
    }
    $target = ROOT_PATH . '/config/config.php';
    if (!is_writable(dirname($target))) {
        return [false, 'The config/ directory is not writable. Create config/config.php manually using config/config.example.php.'];
    }
    if (@file_put_contents($target, $template) === false) {
        return [false, 'Could not write config/config.php. Check folder permissions (0755).'];
    }
    @chmod($target, 0640);
    return [true, 'Configuration saved to config/config.php.'];
}

/** Run the schema file statement by statement. */
function install_schema(): array
{
    $sql = (string) file_get_contents(ROOT_PATH . '/database/schema.sql');
    $pdo = Database::pdo();
    $pdo->exec('SET NAMES utf8mb4');
    $statements = [];
    $buffer = '';
    foreach (preg_split('/\r\n|\r|\n/', $sql) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            continue;
        }
        $buffer .= $line . "\n";
        if (str_ends_with($trimmed, ';')) {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }
    $executed = 0;
    foreach ($statements as $statement) {
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
        $executed++;
    }
    return [true, 'Schema imported (' . $executed . ' statements).'];
}

$requirements = install_requirements();
$requirementsOk = !in_array(false, $requirements, true);
$alreadyConfigured = is_file($configFile);
$dbError = null;
$dbOk = false;

if ($alreadyConfigured) {
    try {
        Database::pdo();
        $dbOk = true;
        $tables = (int) Database::int("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()");
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

if (is_post() && !$installed) {
    if (!Csrf::check((string) post('_token'))) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        switch ($step) {
            case 1:
                $input = [
                    'db_host' => trim((string) post('db_host', 'localhost')),
                    'db_port' => (int) post('db_port', '3306'),
                    'db_name' => trim((string) post('db_name', '')),
                    'db_user' => trim((string) post('db_user', '')),
                    'db_pass' => (string) post('db_pass', ''),
                    'app_url' => rtrim(trim((string) post('app_url', '')), '/'),
                    'debug' => (bool) post('debug'),
                    'secure_cookies' => (bool) post('secure_cookies'),
                ];
                foreach (['db_name', 'db_user', 'app_url'] as $required) {
                    if ($input[$required] === '') {
                        $errors[] = 'Please complete every field.';
                        break;
                    }
                }
                if ($errors === []) {
                    try {
                        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $input['db_host'], $input['db_port'], $input['db_name']);
                        new PDO($dsn, $input['db_user'], $input['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                    } catch (Throwable $e) {
                        $errors[] = 'Could not connect to MySQL: ' . $e->getMessage();
                    }
                }
                if ($errors === []) {
                    [$ok, $message] = install_write_config($input);
                    if (!$ok) {
                        $errors[] = $message;
                    } else {
                        flash('success', $message);
                        redirect('/install/index.php?step=2');
                    }
                }
                break;

            case 2:
                try {
                    [$ok, $message] = install_schema();
                    flash('success', $message);
                    redirect('/install/index.php?step=3');
                } catch (Throwable $e) {
                    $errors[] = 'Import failed: ' . $e->getMessage();
                }
                break;

            case 3:
                $name = trim((string) post('admin_name', ''));
                $email = strtolower(trim((string) post('admin_email', '')));
                $password = (string) post('admin_password', '');
                $confirm = (string) post('admin_password_confirm', '');
                $seedDemo = (bool) post('seed_demo');

                if (mb_strlen($name) < 2) {
                    $errors[] = 'Enter the administrator name.';
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Enter a valid email address.';
                }
                $passwordErrors = Auth::passwordErrors($password);
                if ($passwordErrors !== []) {
                    $errors = array_merge($errors, $passwordErrors);
                }
                if ($password !== $confirm) {
                    $errors[] = 'The passwords do not match.';
                }

                if ($errors === []) {
                    try {
                        if (Users::emailExists($email)) {
                            $errors[] = 'An account with that email already exists.';
                        } else {
                            $adminId = Users::create([
                                'name' => mb_substr($name, 0, 120),
                                'email' => $email,
                                'role' => 'admin',
                                'status' => 'active',
                                'password_hash' => Auth::hashPassword($password),
                            ]);
                            Settings::set('store_name', (string) post('store_name', 'SoftMyMarket'));
                            Settings::set('support_email', $email);
                            Audit::log('install.completed', 'user', $adminId);

                            if ($seedDemo) {
                                define('SMM_SEED_ALLOW_WEB', true);
                                require ROOT_PATH . '/tools/seed.php';
                                // The seeder reports progress on stdout; capture it so it
                                // never lands in the middle of the installer's HTML.
                                ob_start();
                                try {
                                    $result = seed_demo_data();
                                    $seedLog = (string) ob_get_clean();
                                    flash('info', 'Demo catalogue seeded: ' . number_format($result['products']) . ' products, ' . number_format($result['customers']) . ' customers.');
                                } catch (Throwable $seedError) {
                                    // A slow or restricted host must not lose the admin
                                    // account that was just created.
                                    $seedLog = (string) ob_get_clean();
                                    error_log('Demo seeding failed during install: ' . $seedError->getMessage());
                                    flash('warning', 'Your store is installed, but the demo catalogue could not be seeded on this host. Run "php tools/seed.php --demo" from the command line, or add products from the dashboard.');
                                }
                            }

                            @file_put_contents($lockFile, json_encode([
                                'installed_at' => date('c'),
                                'version' => SMM_VERSION,
                                'admin' => $email,
                            ], JSON_PRETTY_PRINT));
                            @chmod($lockFile, 0640);
                            redirect('/install/index.php?done=1');
                        }
                    } catch (Throwable $e) {
                        $errors[] = 'Could not create the administrator: ' . $e->getMessage();
                    }
                }
                break;
        }
    }
}

$done = (bool) query('done') || $installed;
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Install SoftMyMarket</title>
  <link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css">
  <link rel="stylesheet" href="/assets/css/app.css">
  <style>body{background:var(--ink-50)}.install-card{max-width:720px;margin:2rem auto;background:#fff;border:1px solid var(--ink-200);border-radius:1rem;padding:1.5rem}</style>
</head>
<body>
<div class="container">
  <div class="install-card">
    <div class="d-flex align-items-center gap-2 mb-3">
      <span class="logo-mark">SM</span>
      <div><strong>SoftMyMarket</strong><div class="small text-ink-500">Installer · v<?= h(SMM_VERSION) ?></div></div>
    </div>

    <?php foreach (flashes() as $flashItem): ?>
      <div class="alert alert-<?= h($flashItem['type']) ?> py-2 small"><?= h($flashItem['message']) ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $error): ?>
      <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
    <?php endforeach; ?>

    <?php if ($done): ?>
      <h1 class="h4 fw-bold">Setup complete</h1>
      <p class="small text-ink-600">The store is installed and locked. For safety, delete the <code>install/</code> folder (or keep it — the lock file stops it running again).</p>
      <ol class="small text-ink-600">
        <li>Sign in at <a href="/login.php">/login.php</a> with the administrator account.</li>
        <li>Open <a href="/admin/settings.php">Settings</a> and add your Seylan Bank / KOKO / Payzy / Mintpay credentials.</li>
        <li>Run the full seed (10,000 products, 10,000 customers) from the command line: <code>php tools/seed.php --products=10000 --customers=10000</code>.</li>
        <li>Set <code>config/config.php</code> to 0640 and turn off <code>debug</code>.</li>
      </ol>
      <a class="btn btn-brand" href="/">Open the storefront</a>
    <?php else: ?>
      <ul class="nav nav-pills mb-3 small">
        <?php foreach ([1 => 'Database', 2 => 'Schema', 3 => 'Administrator'] as $number => $label): ?>
          <li class="nav-item"><span class="nav-link <?= $step === $number ? 'active' : '' ?> disabled"><?= $number ?>. <?= h($label) ?></span></li>
        <?php endforeach; ?>
      </ul>

      <?php if ($step === 1): ?>
        <h1 class="h5 fw-bold">Requirements</h1>
        <ul class="list-unstyled small mb-3">
          <?php foreach ($requirements as $label => $ok): ?>
            <li class="d-flex justify-content-between border-bottom py-1">
              <span><?= h($label) ?></span>
              <span class="<?= $ok ? 'text-success' : 'text-danger' ?>"><?= $ok ? 'OK' : 'Missing' ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if (!$requirementsOk): ?>
          <div class="alert alert-warning small">Fix the missing requirements above with your host before continuing.</div>
        <?php endif; ?>

        <?php if ($alreadyConfigured && $dbOk): ?>
          <div class="alert alert-info small">A configuration file already exists and the database is reachable. Continue to import the schema.</div>
          <a class="btn btn-brand" href="/install/index.php?step=2">Continue</a>
        <?php else: ?>
          <?php if ($dbError !== null): ?><div class="alert alert-warning small">Existing configuration could not connect: <?= h($dbError) ?></div><?php endif; ?>
          <form method="post" class="row g-2">
            <?= Csrf::field() ?>
            <input type="hidden" name="step" value="1">
            <div class="col-md-8"><label class="form-label small">Database host</label><input class="form-control form-control-sm" name="db_host" value="<?= h((string) post('db_host', 'localhost')) ?>" required></div>
            <div class="col-md-4"><label class="form-label small">Port</label><input class="form-control form-control-sm" name="db_port" value="<?= h((string) post('db_port', '3306')) ?>" required></div>
            <div class="col-md-6"><label class="form-label small">Database name</label><input class="form-control form-control-sm" name="db_name" value="<?= h((string) post('db_name', '')) ?>" required></div>
            <div class="col-md-6"><label class="form-label small">Database user</label><input class="form-control form-control-sm" name="db_user" value="<?= h((string) post('db_user', '')) ?>" required></div>
            <div class="col-12"><label class="form-label small">Database password</label><input class="form-control form-control-sm" type="password" name="db_pass" value=""></div>
            <div class="col-12"><label class="form-label small">Store URL (no trailing slash)</label><input class="form-control form-control-sm" name="app_url" value="<?= h((string) post('app_url', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost'))) ?>" required></div>
            <div class="col-12">
              <div class="form-check"><input class="form-check-input" type="checkbox" id="i-secure" name="secure_cookies" value="1" checked><label class="form-check-label small" for="i-secure">Require HTTPS for session cookies (recommended)</label></div>
              <div class="form-check"><input class="form-check-input" type="checkbox" id="i-debug" name="debug" value="1"><label class="form-check-label small" for="i-debug">Enable debug mode (development only)</label></div>
            </div>
            <div class="col-12 mt-2"><button class="btn btn-brand" type="submit" <?= $requirementsOk ? '' : 'disabled' ?>>Save configuration</button></div>
          </form>
        <?php endif; ?>

      <?php elseif ($step === 2): ?>
        <h1 class="h5 fw-bold">Import the database</h1>
        <p class="small text-ink-600">This creates all 23 tables from <code>database/schema.sql</code>. <strong>Any existing SoftMyMarket tables in this database will be dropped.</strong></p>
        <?php if (!$alreadyConfigured): ?>
          <div class="alert alert-warning small">No configuration file found — go back to step 1.</div>
          <a class="btn btn-soft" href="/install/index.php?step=1">Back</a>
        <?php else: ?>
          <form method="post" data-install-confirm>
            <?= Csrf::field() ?>
            <input type="hidden" name="step" value="2">
            <button class="btn btn-brand" type="submit">Import schema</button>
          </form>
        <?php endif; ?>

      <?php else: ?>
        <h1 class="h5 fw-bold">Create the administrator</h1>
        <p class="small text-ink-600">This account has full access to the dashboard. Use a strong, unique password.</p>
        <form method="post" class="row g-2">
          <?= Csrf::field() ?>
          <input type="hidden" name="step" value="3">
          <div class="col-md-6"><label class="form-label small">Store name</label><input class="form-control form-control-sm" name="store_name" value="<?= h((string) post('store_name', 'SoftMyMarket')) ?>"></div>
          <div class="col-md-6"><label class="form-label small">Your name</label><input class="form-control form-control-sm" name="admin_name" value="<?= h((string) post('admin_name', '')) ?>" required></div>
          <div class="col-md-6"><label class="form-label small">Email</label><input class="form-control form-control-sm" type="email" name="admin_email" value="<?= h((string) post('admin_email', '')) ?>" required></div>
          <div class="col-md-6"><label class="form-label small">Password</label><input class="form-control form-control-sm" type="password" name="admin_password" minlength="8" required></div>
          <div class="col-md-6"><label class="form-label small">Confirm password</label><input class="form-control form-control-sm" type="password" name="admin_password_confirm" minlength="8" required></div>
          <div class="col-12">
            <div class="form-check"><input class="form-check-input" type="checkbox" id="i-seed" name="seed_demo" value="1"><label class="form-check-label small" for="i-seed">Seed demo categories, brands and ~120 sample products</label></div>
          </div>
          <div class="col-12 mt-2"><button class="btn btn-brand" type="submit">Finish installation</button></div>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<script nonce="<?= e(Security::nonce()) ?>">
  // Confirm before replacing tables (kept out of inline onsubmit for the CSP).
  document.querySelectorAll('form[data-install-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!window.confirm('Import the schema now? Existing SoftMyMarket tables will be replaced.')) {
        event.preventDefault();
      }
    });
  });
</script>
</body>
</html>

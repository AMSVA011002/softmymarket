<?php
/**
 * Deployment tools — runtime diagnostics and the web data seeder.
 *
 * Created for hosts without SSH/cron (free shared hosting): everything that
 * would normally be a CLI command is available here behind `settings.manage`.
 */
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
admin_require('settings.manage');

/** Extension we genuinely need vs. nice to have. */
$requirements = [
    ['pdo_mysql', extension_loaded('pdo_mysql'), true, 'Database driver — the store cannot run without it.'],
    ['mbstring', extension_loaded('mbstring'), true, 'Multibyte text (Sinhala/Tamil product names).'],
    ['openssl', extension_loaded('openssl'), true, 'Password-reset tokens and gateway reference encryption.'],
    ['json', extension_loaded('json'), true, 'Settings and gallery storage.'],
    ['fileinfo', extension_loaded('fileinfo'), true, 'Upload validation for payment slips.'],
    ['curl', function_exists('curl_init'), false, 'Faster Google-Drive image proxy. Falls back to PHP streams.'],
    ['gd', function_exists('imagecreatefromstring'), false, 'Downscales uploaded slips. Files are stored as-is without it.'],
    ['intl', extension_loaded('intl'), false, 'Nicer currency/locale formatting. Optional.'],
];

$writable = [
    'config/' => ROOT_PATH . '/config',
    'storage/' => STORAGE_PATH,
    'storage/logs/' => STORAGE_PATH . '/logs',
    'storage/cache/' => STORAGE_PATH . '/cache',
    'storage/uploads/' => STORAGE_PATH . '/uploads',
];

$dbVersion = '—';
$sqlMode = '—';
$tableCounts = [];
$dbError = null;
try {
    $dbVersion = (string) Database::value('SELECT VERSION()', [], '—');
    $sqlMode = (string) Database::value('SELECT @@sql_mode', [], '—');
    foreach (['users', 'categories', 'brands', 'products', 'orders', 'coupons', 'reviews'] as $table) {
        $tableCounts[$table] = Database::int('SELECT COUNT(*) FROM ' . Database::table($table));
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$lockFile = STORAGE_PATH . '/installed.lock';
$installerLocked = is_file($lockFile);
$adminCount = 0;
try {
    $adminCount = Database::int("SELECT COUNT(*) FROM " . Database::table('users') . " WHERE role = 'admin' AND status = 'active'");
} catch (Throwable) {
    $adminCount = 0;
}

$adminTitle = 'Tools';
$adminSubtitle = 'Server diagnostics, data seeding and installer safety';
$adminActions = '<a class="btn btn-sm btn-soft" href="' . e(admin_url('settings.php')) . '"><i class="bi bi-gear me-1"></i> Settings</a>';
require __DIR__ . '/includes/layout-top.php';
?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card-soft p-3 mb-3">
      <h2 class="h6 fw-bold mb-3">Runtime</h2>
      <?php if ($dbError !== null): ?>
        <div class="alert alert-danger small py-2 mb-3">
          <strong>Database not reachable.</strong> <?= e($dbError) ?><br>
          Check <code>config/config.php</code> (host, database, user, password) and that the MySQL user is attached to the database.
        </div>
      <?php endif; ?>
      <div class="table-responsive">
        <table class="table table-sm table-admin align-middle mb-0">
          <tbody>
            <tr><th style="width:42%">PHP version</th><td><?= e(PHP_VERSION) ?> <span class="text-ink-500">(<?= e(PHP_SAPI) ?>)</span></td></tr>
            <tr><th>Server software</th><td><?= e((string) ($_SERVER['SERVER_SOFTWARE'] ?? '—')) ?></td></tr>
            <tr><th>MySQL / MariaDB</th><td><?= e($dbVersion) ?></td></tr>
            <tr><th>Timezone</th><td><?= e(date_default_timezone_get()) ?> · <?= e(date('d M Y H:i')) ?></td></tr>
            <tr><th>Application URL</th><td><?= e(Config::get('app.url') !== '' ? (string) Config::get('app.url') : '(auto-detected) ' . e(url('/'))) ?></td></tr>
            <tr><th>Debug mode</th><td><?= Config::get('app.debug') ? '<span class="badge text-bg-danger">ON</span> — turn this off in production' : '<span class="badge text-bg-success">off</span>' ?></td></tr>
            <tr><th>Mail transport</th><td><?= e((string) Config::get('mail.transport', 'mail')) ?></td></tr>
            <tr><th>Installer lock</th><td><?= $installerLocked
                ? '<span class="badge text-bg-success">installed.lock present</span>'
                : '<span class="badge text-bg-warning">not locked</span> — create the lock file once the store is live' ?></td></tr>
            <tr><th>Active administrators</th><td><?= (int) $adminCount ?></td></tr>
          </tbody>
        </table>
      </div>
      <?php if (!$installerLocked): ?>
        <form method="post" action="/admin/actions.php" class="mt-3" data-disable-on-submit>
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="installer_lock">
          <input type="hidden" name="return" value="<?= e(admin_url('tools.php')) ?>">
          <button class="btn btn-sm btn-soft" type="submit"><i class="bi bi-lock me-1"></i> Lock the installer</button>
          <span class="small text-ink-500 ms-2">Creates <code>storage/installed.lock</code> so <code>/install/</code> can never overwrite this database.</span>
        </form>
      <?php endif; ?>
    </div>

    <div class="card-soft p-3">
      <h2 class="h6 fw-bold mb-3">Extensions</h2>
      <ul class="list-unstyled mb-0 small">
        <?php foreach ($requirements as [$name, $ok, $required, $why]): ?>
          <li class="d-flex gap-2 py-1 border-bottom">
            <i class="bi <?= $ok ? 'bi-check-circle-fill text-success' : ($required ? 'bi-x-circle-fill text-danger' : 'bi-dash-circle text-ink-400') ?>"></i>
            <div>
              <strong><?= e($name) ?></strong>
              <?php if (!$required): ?><span class="badge text-bg-light ms-1">optional</span><?php endif; ?>
              <div class="text-ink-500"><?= e($why) ?></div>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card-soft p-3 mb-3">
      <h2 class="h6 fw-bold mb-3">Rows in the database</h2>
      <ul class="list-unstyled mb-0 small">
        <?php foreach ($tableCounts as $table => $count): ?>
          <li class="d-flex justify-content-between py-1 border-bottom">
            <span class="text-ink-600"><?= e(humanize($table)) ?></span>
            <strong><?= e(number_format($count)) ?></strong>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div class="card-soft p-3 mb-3">
      <h2 class="h6 fw-bold mb-2">Writable folders</h2>
      <ul class="list-unstyled mb-0 small">
        <?php foreach ($writable as $label => $path): ?>
          <li class="d-flex justify-content-between py-1 border-bottom">
            <span class="text-ink-600"><code><?= e($label) ?></code></span>
            <span class="<?= is_writable($path) ? 'text-success' : 'text-danger' ?>"><?= is_writable($path) ? 'writable' : 'not writable (chmod 755/775)' ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div class="card-soft p-3">
      <h2 class="h6 fw-bold mb-2">Seed demo data</h2>
      <p class="small text-ink-600">Adds categories, brands, coupons, products, customers and orders using the same generator as <code>php tools/seed.php</code>. Intended for the free-hosting plan where there is no SSH access — run it in a few small batches.</p>
      <form method="post" action="/admin/actions.php" class="row g-2" data-disable-on-submit>
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="seed_demo">
        <input type="hidden" name="return" value="<?= e(admin_url('tools.php')) ?>">
        <div class="col-4">
          <label class="form-label small mb-1">Products</label>
          <input class="form-control form-control-sm" type="number" name="products" value="120" min="0" max="2000">
        </div>
        <div class="col-4">
          <label class="form-label small mb-1">Customers</label>
          <input class="form-control form-control-sm" type="number" name="customers" value="50" min="0" max="2000">
        </div>
        <div class="col-4">
          <label class="form-label small mb-1">Orders</label>
          <input class="form-control form-control-sm" type="number" name="orders" value="25" min="0" max="200">
        </div>
        <div class="col-12">
          <button class="btn btn-sm btn-brand w-100" type="submit"><i class="bi bi-magic me-1"></i> Generate</button>
        </div>
      </form>
      <p class="small text-ink-500 mt-2 mb-0">Large batches may hit the host's execution time limit. 120 products / 50 customers per run is safe on free hosting; press it a few times to grow the catalogue.</p>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/layout-bottom.php'; ?>

<?php
/**
 * Dashboard shell — sidebar, top bar and the opening .admin-content wrapper.
 * Pages set $adminTitle (required) and optionally $adminSubtitle / $adminActions.
 */
declare(strict_types=1);

$adminTitle = $adminTitle ?? 'Dashboard';
$adminSubtitle = $adminSubtitle ?? '';
$adminActions = $adminActions ?? '';
$adminCounts = admin_nav_counts();
$adminFl = flashes();
?><!doctype html>
<html lang="en-LK">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($adminTitle) ?> · <?= e($adminBrand['name']) ?> admin</title>
  <link rel="icon" href="<?= e(trim($adminBrand['favicon']) !== '' ? img_url($adminBrand['favicon'], $adminBrand['name'], 128) : asset('assets/img/favicon.svg')) ?>">
  <link rel="stylesheet" href="<?= e(asset('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('assets/vendor/bootstrap-icons/bootstrap-icons.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
  <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
</head>
<body class="admin-body">
<a class="visually-hidden-focusable position-absolute top-0 start-0 m-2 btn btn-brand btn-sm" href="#adminMain">Skip to content</a>
<div class="admin-shell">
  <aside class="admin-sidebar" data-admin-sidebar>
    <a class="brand<?= !empty($adminBrand['logo']) ? ' has-brand-logo' : '' ?>" href="/admin/index.php">
      <?php if (!empty($adminBrand['logo'])): ?>
        <img class="admin-brand-logo" src="<?= e(img_url($adminBrand['logo'], $adminBrand['name'], 320)) ?>" alt="<?= e($adminBrand['name']) ?>" width="150" height="40">
        <span class="brand-caption"><small>Dashboard</small></span>
      <?php else: ?>
        <strong><?= e($adminBrand['name']) ?></strong>
      <?php endif; ?>
    </a>
    <?php foreach (admin_nav_groups() as $groupLabel => $items): ?>
      <ul class="admin-nav">
        <li class="nav-label"><?= e($groupLabel) ?></li>
        <?php foreach ($items as [$label, $href, $icon, $permission, $badgeKey]):
            if (!Auth::can($permission)) { continue; }
            $badge = $badgeKey !== null ? (int) ($adminCounts[$badgeKey] ?? 0) : 0; ?>
          <li>
            <a class="<?= admin_active($href) ? 'active' : '' ?>" href="<?= e(admin_url($href)) ?>">
              <i class="bi <?= e($icon) ?>"></i><span><?= e($label) ?></span>
              <?php if ($badge > 0): ?><span class="badge rounded-pill"><?= $badge > 99 ? '99+' : $badge ?></span><?php endif; ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endforeach; ?>
    <ul class="admin-nav">
      <li class="nav-label">Shortcuts</li>
      <li><a href="/" target="_blank" rel="noopener"><i class="bi bi-shop"></i><span>View storefront</span></a></li>
      <li><a href="<?= e(admin_url('account.php')) ?>"><i class="bi bi-person-gear"></i><span>My account</span></a></li>
      <li>
        <form method="post" action="/logout.php" class="px-1 pt-2">
          <?= Csrf::field() ?>
          <button class="btn btn-sm w-100" type="submit" style="background:rgba(255,255,255,.08);color:#cfe9d5;border:1px solid rgba(255,255,255,.12)">
            <i class="bi bi-box-arrow-right me-1"></i> Sign out
          </button>
        </form>
      </li>
    </ul>
    <p class="px-2 pb-3 mb-0" style="font-size:.62rem;color:#5f8468">Signed in as <?= e($adminUser['name']) ?> · <?= e(role_label((string) $adminUser['role'])) ?></p>
  </aside>

  <div class="admin-main">
    <div class="admin-topbar">
      <button class="btn btn-sm btn-soft d-lg-none" type="button" data-admin-toggle aria-label="Open menu"><i class="bi bi-list"></i></button>
      <form class="flex-grow-1" method="get" action="<?= e(admin_url('orders.php')) ?>" role="search">
        <div class="input-group input-group-sm" style="max-width:420px">
          <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
          <input class="form-control" name="q" placeholder="Order number, name, phone, email…" value="<?= e(query('q', '')) ?>" aria-label="Search orders">
        </div>
      </form>
      <a class="btn btn-sm btn-soft position-relative" href="<?= e(admin_url('live.php')) ?>" title="Live orders">
        <i class="bi bi-broadcast-pin"></i>
        <span class="d-none d-md-inline ms-1">Live</span>
      </a>
      <div class="dropdown">
        <button class="btn btn-sm btn-soft dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
          <i class="bi bi-person-circle me-1"></i><span class="d-none d-sm-inline"><?= e(limit_text($adminUser['name'], 18)) ?></span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li class="dropdown-header"><?= e($adminUser['email']) ?></li>
          <li><a class="dropdown-item" href="<?= e(admin_url('account.php')) ?>"><i class="bi bi-person-gear me-1"></i> My account</a></li>
          <?php if (Auth::isAdmin()): ?>
            <li><a class="dropdown-item" href="<?= e(admin_url('settings.php')) ?>"><i class="bi bi-gear me-1"></i> Store settings</a></li>
          <?php endif; ?>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item" href="/" target="_blank" rel="noopener"><i class="bi bi-shop me-1"></i> View storefront</a></li>
        </ul>
      </div>
    </div>

    <div class="admin-content" id="adminMain">
      <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
        <div>
          <h1 class="h5 fw-bold mb-1"><?= e($adminTitle) ?></h1>
          <?php if ($adminSubtitle !== ''): ?><p class="small text-ink-500 mb-0"><?= e($adminSubtitle) ?></p><?php endif; ?>
        </div>
        <?php if ($adminActions !== ''): ?><div class="d-flex flex-wrap gap-2"><?= $adminActions ?></div><?php endif; ?>
      </div>
      <?php if ($adminFl !== []): ?>
        <?php foreach ($adminFl as $flashItem): ?>
          <div class="alert alert-<?= e($flashItem['type'] === 'error' ? 'danger' : $flashItem['type']) ?> alert-dismissible fade show py-2 small" role="alert">
            <?= e($flashItem['message']) ?>
            <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

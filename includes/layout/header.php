<?php
/**
 * Shared storefront header.
 *
 * Pages set $pageTitle / $pageDescription / $pageClass before including this
 * file. Everything else (navigation, cart counts, notifications) is resolved
 * here so templates stay focused on their own content.
 */
declare(strict_types=1);

$brand = Settings::brand();
$smmUser = Auth::user();
$smmCartCount = Cart::count();
$smmWishCount = count(Cart::wishlistIds());
$smmUnread = Notifier::unreadCount($smmUser['id'] ?? null);
$smmTree = View::navCategories();
$smmAnnouncement = trim(Settings::get('announcement'));
$smmTitle = isset($pageTitle) && $pageTitle !== null
    ? $pageTitle . ' · ' . $brand['name']
    : Settings::get('default_meta_title', $brand['name'] . ' — ' . ($brand['tagline'] !== '' ? $brand['tagline'] : 'Online store'));
$smmDescription = isset($pageDescription) && $pageDescription !== ''
    ? $pageDescription
    : Settings::get('default_meta_description', Settings::get('store_description'));
if (isset($pageCanonical) && $pageCanonical !== '') {
    $smmCanonical = $pageCanonical;
} else {
    // One canonical URL per page: fold /index.php into /, and drop the host's
    // ?i=1 challenge marker plus the usual campaign noise so search engines do
    // not index the same page several times.
    $smmCanonicalPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    $smmCanonicalPath = preg_replace('#/index\.php$#', '/', $smmCanonicalPath) ?: '/';
    $smmCanonicalQuery = [];
    parse_str((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY), $smmCanonicalQuery);
    foreach (['i', 'fbclid', 'gclid', 'msclkid', 'source',
              'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $smmNoise) {
        unset($smmCanonicalQuery[$smmNoise]);
    }
    $smmCanonical = url($smmCanonicalPath, $smmCanonicalQuery);
}
$smmNoindex = !empty($noindex);
$smmPath = current_path();

/* ------------------------------------------------------------ share metadata
 * One place decides the icon, the social preview and the structured data, so
 * every page ships the same complete <head>. Icons follow the Favicon setting
 * (Drive link or URL) and fall back to the bundled PNG/ICO set; the share image
 * falls back to a designed cover so a link never previews empty.
 */
$smmFavicon = trim(Settings::get('favicon_url'));
$smmIconSrc = $smmFavicon !== ''
    ? img_url($smmFavicon, $brand['name'], 180)
    : asset('assets/img/favicon-32.png');
$smmTouchIcon = $smmFavicon !== ''
    ? img_url($smmFavicon, $brand['name'], 180)
    : asset('assets/img/apple-touch-icon.png');
$smmSocialImage = trim(Settings::get('social_image_url'));
$smmShareImage = $smmSocialImage !== ''
    ? url(img_url($smmSocialImage, $brand['name'], 1200))
    : (!empty($brand['logo'])
        ? url(img_url((string) $brand['logo'], $brand['name'], 1200))
        : url(asset('assets/img/og-cover.png')));
$smmLanguage = Settings::get('store_language', 'en-LK');
$smmOgLocale = str_replace('-', '_', $smmLanguage);
$smmTwitterUrl = trim(Settings::get('twitter_url'));
$smmTwitterHandle = '';
if ($smmTwitterUrl !== '') {
    $smmTwitterPath = (string) parse_url($smmTwitterUrl, PHP_URL_PATH);
    $smmTwitterHandle = $smmTwitterPath !== '' ? '@' . ltrim(basename($smmTwitterPath), '@') : '';
}
$smmSameAs = [];
foreach (['facebook_url', 'instagram_url', 'tiktok_url', 'youtube_url', 'twitter_url'] as $smmSocialKey) {
    $smmSocialValue = trim(Settings::get($smmSocialKey));
    if ($smmSocialValue !== '') {
        $smmSameAs[] = $smmSocialValue;
    }
}
$smmStoreId = url('/') . '#store';
$smmWebsiteId = url('/') . '#website';
$smmStructuredData = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type'        => 'OnlineStore',
            '@id'          => $smmStoreId,
            'name'         => $brand['name'],
            'url'          => url('/'),
            'description'  => limit_text(Settings::get('store_description'), 300, ''),
            'image'        => $smmShareImage,
            'logo'         => url($smmTouchIcon),
            'telephone'    => Settings::get('support_phone'),
            'email'        => Settings::get('support_email'),
            'priceRange'   => Settings::get('currency_symbol', 'Rs'),
            'currenciesAccepted' => Settings::get('currency', 'LKR'),
            'areaServed'   => Settings::get('store_country', 'Sri Lanka'),
            'paymentAccepted'    => 'Payment methods shown during checkout',
            'address'      => [
                '@type'           => 'PostalAddress',
                'streetAddress'   => Settings::get('address'),
                'addressLocality' => Settings::get('store_locality', 'Colombo'),
                'addressCountry'  => Settings::get('store_country_code', 'LK'),
            ],
            'sameAs'       => $smmSameAs,
        ],
        [
            '@type'       => 'WebSite',
            '@id'         => $smmWebsiteId,
            'url'         => url('/'),
            'name'        => $brand['name'],
            'inLanguage'  => $smmLanguage,
            'publisher'   => ['@id' => $smmStoreId],
            'potentialAction' => [
                '@type'       => 'SearchAction',
                'target'      => [
                    '@type'       => 'EntryPoint',
                    'urlTemplate' => url('/shop.php') . '?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ],
    ],
];
?>
<!doctype html>
<html lang="<?= e($smmLanguage) ?>" data-currency-symbol="<?= e(Settings::get('currency_symbol', 'Rs')) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= e($smmTitle) ?></title>
  <meta name="description" content="<?= e(limit_text($smmDescription, 158, '')) ?>">
  <link rel="canonical" href="<?= e($smmCanonical) ?>">
  <meta name="robots" content="<?= $smmNoindex
      ? 'noindex, follow'
      : 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1' ?>">
  <meta name="theme-color" content="<?= e($brand['color']) ?>">
  <meta property="og:type" content="<?= e($pageOgType ?? 'website') ?>">
  <meta property="og:site_name" content="<?= e($brand['name']) ?>">
  <meta property="og:locale" content="<?= e($smmOgLocale) ?>">
  <meta property="og:title" content="<?= e($smmTitle) ?>">
  <meta property="og:description" content="<?= e(limit_text($smmDescription, 200, '')) ?>">
  <meta property="og:url" content="<?= e($smmCanonical) ?>">
  <meta property="og:image" content="<?= e($smmShareImage) ?>">
  <meta property="og:image:alt" content="<?= e($brand['name'] . ' — ' . Settings::get('store_tagline')) ?>">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= e($smmTitle) ?>">
  <meta name="twitter:description" content="<?= e(limit_text($smmDescription, 190, '')) ?>">
  <meta name="twitter:image" content="<?= e($smmShareImage) ?>">
  <meta name="twitter:image:alt" content="<?= e($brand['name']) ?>">
  <?php if ($smmTwitterHandle !== ''): ?><meta name="twitter:site" content="<?= e($smmTwitterHandle) ?>"><?php endif; ?>
  <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/svg+xml" href="<?= e(asset('assets/img/favicon.svg')) ?>">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= e($smmIconSrc) ?>">
  <link rel="apple-touch-icon" sizes="180x180" href="<?= e($smmTouchIcon) ?>">
  <link rel="manifest" href="<?= e(asset('manifest.php')) ?>">
  <link rel="stylesheet" href="<?= e(asset('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('assets/vendor/bootstrap-icons/bootstrap-icons.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
  <?php if (!$smmNoindex): ?>
    <script type="application/ld+json" nonce="<?= e(Security::nonce()) ?>"><?= json_encode($smmStructuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <?php endif; ?>
  <style nonce="<?= e(Security::nonce()) ?>">:root{<?= e(Settings::themeCssVars()) ?>}</style>
</head>
<body class="<?= e($pageClass ?? '') ?>">
<header class="site-header">
  <a class="visually-hidden-focusable position-absolute top-0 start-0 m-2 btn btn-brand btn-sm" href="#main">Skip to content</a>
  <?php if ($smmAnnouncement !== ''): ?>
    <div class="topbar">
      <div class="container-page d-flex align-items-center justify-content-center gap-2">
        <i class="bi bi-truck" aria-hidden="true"></i>
        <span class="text-center"><?= e($smmAnnouncement) ?></span>
      </div>
    </div>
  <?php endif; ?>
  <div class="container-page">
    <div class="header-main">
      <button class="btn btn-icon d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#navDrawer" aria-label="Open menu" aria-controls="navDrawer">
        <i class="bi bi-list fs-4"></i>
      </button>

      <a class="logo" href="/" aria-label="<?= e($brand['name']) ?> home">
        <?php if (!empty($brand['logo'])): ?>
          <img src="<?= e(img_url($brand['logo'], $brand['name'], 320)) ?>" alt="<?= e($brand['name']) ?>" height="38">
        <?php else: ?>
          <span class="logo-mark"><?= e($brand['initials']) ?></span>
          <span class="logo-text"><?= e($brand['name']) ?></span>
        <?php endif; ?>
      </a>

      <form class="search-shell" action="/shop.php" method="get" role="search" data-live-search>
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" name="q" value="<?= e((string) query('q', '')) ?>" placeholder="Search products, brands and categories"
               aria-label="Search products" autocomplete="off" autocapitalize="off" spellcheck="false"
               enterkeyhint="search" data-search-input>
        <button class="btn btn-brand" type="submit" aria-label="Search"><span class="d-none d-sm-inline">Search</span><i class="bi bi-arrow-right d-sm-none"></i></button>
        <div class="suggest-box d-none" data-suggest-box></div>
      </form>

      <div class="header-actions">
        <a class="action-link d-none d-xl-flex" href="/track.php">
          <i class="bi bi-truck"></i><span>Track order</span>
        </a>
        <a class="action-link d-none d-md-flex" href="/account/wishlist.php">
          <i class="bi bi-heart"></i><span>Wishlist</span>
          <span class="action-count <?= $smmWishCount === 0 ? 'is-empty' : '' ?>" data-wishlist-count><?= (int) $smmWishCount ?></span>
        </a>
        <?php if ($smmUser !== null): ?>
          <a class="action-link d-none d-md-flex" href="/account/notifications.php">
            <i class="bi bi-bell"></i><span>Alerts</span>
            <span class="action-count <?= $smmUnread === 0 ? 'is-empty' : '' ?>" data-notif-unread><?= (int) $smmUnread ?></span>
          </a>
        <?php endif; ?>
        <button class="action-link" type="button" data-open-cart aria-label="Open cart">
          <i class="bi bi-bag"></i><span class="d-none d-md-inline">Cart</span>
          <span class="action-count <?= $smmCartCount === 0 ? 'is-empty' : '' ?>" data-cart-count><?= (int) $smmCartCount ?></span>
        </button>

        <div class="dropdown">
          <button class="action-link" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <?php if ($smmUser !== null): ?>
              <span class="avatar avatar-sm" style="background:<?= e(Settings::get('brand_color')) ?>"><?= e(initials((string) $smmUser['name'])) ?></span>
              <span class="d-none d-lg-inline"><?= e(explode(' ', (string) $smmUser['name'])[0]) ?></span>
            <?php else: ?>
              <i class="bi bi-person"></i><span class="d-none d-md-inline">Sign in</span>
            <?php endif; ?>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow-sm">
            <?php if ($smmUser !== null): ?>
              <li><div class="dropdown-header text-truncate"><?= e($smmUser['name']) ?></div></li>
              <li><a class="dropdown-item" href="/account/index.php"><i class="bi bi-speedometer2 me-2"></i>My account</a></li>
              <li><a class="dropdown-item" href="/account/orders.php"><i class="bi bi-box-seam me-2"></i>My orders</a></li>
              <li><a class="dropdown-item" href="/account/wishlist.php"><i class="bi bi-heart me-2"></i>Wishlist</a></li>
              <li><a class="dropdown-item" href="/account/addresses.php"><i class="bi bi-geo-alt me-2"></i>Addresses</a></li>
              <li><a class="dropdown-item" href="/account/settings.php"><i class="bi bi-gear me-2"></i>Settings</a></li>
              <?php if (Auth::isTeam()): ?>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item fw-semibold" href="/admin/index.php"><i class="bi bi-grid-1x2 me-2"></i>Dashboard</a></li>
              <?php endif; ?>
              <li><hr class="dropdown-divider"></li>
              <li>
                <form method="post" action="/logout.php" class="px-0">
                  <?= Csrf::field() ?>
                  <button class="dropdown-item text-danger" type="submit"><i class="bi bi-box-arrow-right me-2"></i>Sign out</button>
                </form>
              </li>
            <?php else: ?>
              <li><a class="dropdown-item fw-semibold" href="/login.php"><i class="bi bi-box-arrow-in-right me-2"></i>Sign in</a></li>
              <li><a class="dropdown-item" href="/register.php"><i class="bi bi-person-plus me-2"></i>Create an account</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="/track.php"><i class="bi bi-truck me-2"></i>Track my order</a></li>
              <li><a class="dropdown-item" href="/help.php"><i class="bi bi-question-circle me-2"></i>Help centre</a></li>
            <?php endif; ?>
          </ul>
        </div>
      </div>
    </div>

    <nav class="cat-nav d-none d-lg-flex" aria-label="Departments">
      <button class="all-categories-btn" type="button" data-mega-toggle aria-expanded="false" aria-controls="megaPanel">
        <i class="bi bi-grid-3x3-gap-fill"></i> All categories <i class="bi bi-chevron-down ms-1"></i>
      </button>
      <a href="/shop.php" class="<?= nav_active('/shop.php', true) ? 'active' : '' ?>">Shop all</a>
      <a href="/deals.php" class="<?= nav_active('/deals.php') ? 'active' : '' ?>">Today's deals</a>
      <a href="/brands.php" class="<?= nav_active('/brands.php') ? 'active' : '' ?>">Brands</a>
      <a href="/shop.php?sort=newest" class="d-none d-xl-inline">New arrivals</a>
      <a href="/shop.php?on_sale=1" class="d-none d-xl-inline">Sale</a>
      <a href="/track.php" class="<?= nav_active('/track.php') ? 'active' : '' ?>">Track order</a>
      <span class="ms-auto small text-ink-500 d-none d-xl-flex align-items-center gap-1">
        <i class="bi bi-telephone"></i> <?= e(Settings::get('support_phone')) ?>
      </span>
    </nav>
  </div>

  <div class="mega-panel" id="megaPanel" data-mega-panel>
    <div class="container-page">
      <div class="mega-grid">
        <?php foreach ($smmTree as $dept): ?>
          <div class="mega-group">
            <a class="mega-head" href="/category.php?slug=<?= e($dept['slug']) ?>">
              <i class="bi <?= e($dept['icon']) ?>"></i> <?= e($dept['name']) ?>
            </a>
            <?php if (!empty($dept['children'])): ?>
              <ul>
                <?php foreach (array_slice($dept['children'], 0, 6) as $child): ?>
                  <li><a href="/category.php?slug=<?= e($child['slug']) ?>"><?= e($child['name']) ?></a></li>
                <?php endforeach; ?>
                <?php if (count($dept['children']) > 6): ?>
                  <li><a class="fw-semibold" href="/category.php?slug=<?= e($dept['slug']) ?>">All <?= (int) count($dept['children']) ?> sub-categories</a></li>
                <?php endif; ?>
              </ul>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</header>

<main id="main" class="site-main">
  <div class="container-page mt-2">
    <?= View::flashes() ?>
  </div>

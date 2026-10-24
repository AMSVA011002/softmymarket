<?php
/** Account sidebar navigation. Expects $accountActive to be set by the page. */
$accountActive = $accountActive ?? '';
$accountItems = [
    ['index', '/account/index.php', 'bi-speedometer2', 'Overview'],
    ['orders', '/account/orders.php', 'bi-box-seam', 'My orders'],
    ['wishlist', '/account/wishlist.php', 'bi-heart', 'Wishlist'],
    ['addresses', '/account/addresses.php', 'bi-geo-alt', 'Addresses'],
    ['reviews', '/account/reviews.php', 'bi-star', 'My reviews'],
    ['notifications', '/account/notifications.php', 'bi-bell', 'Notifications'],
    ['settings', '/account/settings.php', 'bi-gear', 'Settings'],
];
$accountUnread = Auth::check() ? Notifier::unreadCount(Auth::id()) : 0;
?>
<nav class="account-nav" aria-label="Account navigation">
  <?php foreach ($accountItems as [$key, $href, $icon, $label]): ?>
    <a class="<?= $accountActive === $key ? 'active' : '' ?>" href="<?= e($href) ?>">
      <i class="bi <?= e($icon) ?>"></i>
      <span><?= e($label) ?></span>
      <?php if ($key === 'notifications' && $accountUnread > 0): ?><span class="badge text-bg-danger ms-auto"><?= (int) $accountUnread ?></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
  <form method="post" action="/logout.php" class="mt-2">
    <?= Csrf::field() ?>
    <button class="btn btn-soft btn-sm w-100" type="submit"><i class="bi bi-box-arrow-right me-1"></i> Sign out</button>
  </form>
</nav>

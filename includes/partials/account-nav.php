<?php
$items = [
    ['/account/index.php', 'bi-speedometer2', 'Dashboard', 'index'],
    ['/account/orders.php', 'bi-box-seam', 'Orders', 'orders'],
    ['/account/wishlist.php', 'bi-heart', 'Wishlist', 'wishlist'],
    ['/account/addresses.php', 'bi-geo-alt', 'Addresses', 'addresses'],
    ['/account/reviews.php', 'bi-star', 'My reviews', 'reviews'],
    ['/account/notifications.php', 'bi-bell', 'Notifications', 'notifications'],
    ['/account/settings.php', 'bi-gear', 'Settings', 'settings'],
];
$current = basename(current_path());
?>
<nav class="account-nav mb-3" aria-label="Account">
  <?php foreach ($items as [$href, $icon, $label, $key]): ?>
    <a href="<?= e($href) ?>" class="<?= $current === $key . '.php' ? 'active' : '' ?>">
      <i class="bi <?= e($icon) ?>"></i> <?= e($label) ?>
      <?php if ($key === 'notifications'):
        $unread = Notifier::unreadCount((int) Auth::id());
        if ($unread > 0): ?><span class="badge text-bg-danger"><?= $unread ?></span><?php endif;
      endif; ?>
    </a>
  <?php endforeach; ?>
</nav>

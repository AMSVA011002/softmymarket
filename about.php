<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'About us';
$pageDescription = limit_text(Settings::get('store_description'), 158, 'Learn about ' . Settings::get('store_name') . '.');
$heroTitle = 'About ' . Settings::get('store_name');
$heroSubtitle = Settings::get('store_tagline');
$heroIcon = 'bi-shop';
require INCLUDES_PATH . '/layout/header.php';
require INCLUDES_PATH . '/partials/page-hero.php';
?>
<section class="section">
  <div class="container-page">
    <div class="row g-4">
      <div class="col-lg-7">
        <div class="card-soft p-3 p-md-4 mb-3">
          <div class="text-ink-700" style="white-space:pre-line;line-height:1.8"><?= e(Settings::get('about_text') !== '' ? Settings::get('about_text') : Settings::get('store_description')) ?></div>
        </div>
        <div class="row g-2">
          <?php foreach ([['bi-bag-check', 'Curated catalogue', 'Explore products selected for everyday needs'], ['bi-cart-check', 'Simple checkout', 'Shop categories in one convenient place'], ['bi-chat-dots', 'Customer support', Settings::get('support_email')], ['bi-credit-card', 'Flexible payment', 'Available payment methods are shown at checkout']] as [$icon, $title, $body]): ?>
            <div class="col-sm-6">
              <div class="card-soft p-3 h-100 d-flex gap-2">
                <i class="bi <?= e($icon) ?> text-brand fs-5"></i>
                <div><div class="fw-semibold small"><?= e($title) ?></div><div class="text-ink-500" style="font-size:.76rem"><?= e($body) ?></div></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="card-soft p-3 p-md-4 mb-3">
          <h2 class="h6 fw-bold mb-2">What we offer</h2>
          <ul class="small text-ink-600 mb-0" style="line-height:1.8">
            <?php foreach (['trust_delivery_text', 'trust_payment_text', 'trust_quality_text', 'trust_returns_text'] as $aboutBenefit): ?>
              <?php if (Settings::get($aboutBenefit) !== ''): ?><li><?= e(Settings::get($aboutBenefit)) ?></li><?php endif; ?>
            <?php endforeach; ?>
          </ul>
        </div>
        <div class="card-soft p-3 p-md-4">
          <h2 class="h6 fw-bold mb-2">Visit us</h2>
          <p class="small text-ink-600 mb-2"><?= e(Settings::get('address')) ?></p>
          <?php if (Settings::get('store_hours') !== ''): ?><p class="small text-ink-500 mb-3"><?= e(Settings::get('store_hours')) ?></p><?php endif; ?>
          <a class="btn btn-brand btn-sm w-100" href="/contact.php"><i class="bi bi-chat-dots me-1"></i> Talk to our team</a>
        </div>
      </div>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

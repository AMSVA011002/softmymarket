<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'About us';
$pageDescription = 'SoftMyMarket is a single-vendor marketplace in Colombo delivering genuine products island-wide.';
$heroTitle = 'About ' . Settings::get('store_name');
$heroSubtitle = 'One trusted seller, 10,000+ products and a delivery promise we take seriously.';
$heroIcon = 'bi-shop';
require INCLUDES_PATH . '/layout/header.php';
require INCLUDES_PATH . '/partials/page-hero.php';
?>
<section class="section">
  <div class="container-page">
    <div class="row g-4">
      <div class="col-lg-7">
        <div class="card-soft p-3 p-md-4 mb-3">
          <div class="text-ink-700" style="white-space:pre-line;line-height:1.8"><?= e(Settings::get('about_text') ?: Settings::get('store_description') . "\n\nWe started SoftMyMarket because buying online in Sri Lanka should be simpler, safer and faster. Instead of juggling dozens of sellers, you buy from one accountable team: the same people answer your call, pack your parcel and chase the courier.\n\nEvery product is sourced directly from authorised distributors or the brand itself. If something is not right, our returns team makes it right — no arguments.\n\nToday we ship to every district in the country from our Colombo warehouse, with cash on delivery, card payments through Seylan Bank, and pay-later options from KOKO and Mintpay.") ?></div>
        </div>
        <div class="row g-2">
          <?php foreach ([['bi-box-seam', '10,000+ SKUs', 'Electronics, fashion, home, grocery and more'], ['bi-people', '10,000+ customers', 'Served across all 25 districts'], ['bi-star', '4.8 average rating', 'From verified buyers'], ['bi-truck', 'Same-day dispatch', 'For orders before 2pm on weekdays']] as [$icon, $title, $body]): ?>
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
          <h2 class="h6 fw-bold mb-2">Why customers stay</h2>
          <ul class="small text-ink-600 mb-0" style="line-height:1.8">
            <li>Genuine stock with local warranty</li>
            <li>Live order tracking from packing to doorstep</li>
            <li>Three ways to pay later, plus COD</li>
            <li>Friendly human support in English, Sinhala and Tamil</li>
            <li>Easy 7-day returns</li>
          </ul>
        </div>
        <div class="card-soft p-3 p-md-4">
          <h2 class="h6 fw-bold mb-2">Visit us</h2>
          <p class="small text-ink-600 mb-2"><?= e(Settings::get('address')) ?></p>
          <p class="small text-ink-500 mb-3">Open Monday to Saturday, 9am – 6pm.</p>
          <a class="btn btn-brand btn-sm w-100" href="/contact.php"><i class="bi bi-chat-dots me-1"></i> Talk to our team</a>
        </div>
      </div>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

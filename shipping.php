<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$freeShip = Settings::float('free_shipping_threshold', 15000);
$flat = Settings::float('default_shipping_fee', 450);
$codFee = (float) Config::get('payments.cod.fee', 0);
$pageTitle = 'Shipping & delivery';
$pageDescription = 'Delivery rates, timelines and courier partners.';
$heroTitle = 'Shipping & delivery';
$heroSubtitle = 'Delivery estimates: ' . Settings::get('delivery_promise') . '. See the rates and terms below.';
$heroIcon = 'bi-truck';
require INCLUDES_PATH . '/layout/header.php';
require INCLUDES_PATH . '/partials/page-hero.php';
?>
<?php if (Settings::get('shipping_text') !== ''): ?>
<section class="section"><div class="container-page"><div class="card-soft p-3 p-md-4" style="white-space:pre-line"><?= e(Settings::get('shipping_text')) ?></div></div></section>
<?php else: ?>
<section class="section">
  <div class="container-page">
    <div class="row g-4">
      <div class="col-lg-8">
        <div class="card-soft p-3 p-md-4 mb-3">
          <h2 class="h6 fw-bold mb-2">Rates at a glance</h2>
          <ul class="small text-ink-600 mb-0" style="line-height:1.9">
            <li>Standard delivery: <strong><?= e(money($flat)) ?></strong> for the first parcel.</li>
            <li>Free delivery on orders over <strong><?= e(money($freeShip)) ?></strong>.</li>
            <?php if ($codFee > 0): ?><li>Cash-on-delivery handling fee: <strong><?= e(money($codFee)) ?></strong>.</li><?php endif; ?>
            <li>Heavy or bulky items may carry an additional courier surcharge, shown before you pay.</li>
          </ul>
        </div>
        <div class="card-soft p-3 p-md-4 mb-3">
          <h2 class="h6 fw-bold mb-2">Timelines</h2>
          <div class="table-responsive">
            <table class="table table-admin mb-0 small">
              <thead><tr><th>Destination</th><th>Dispatch</th><th>Delivery</th></tr></thead>
              <tbody>
                <tr><td>Colombo / Gampaha</td><td>Same day for orders before 2pm</td><td>1 – 2 working days</td></tr>
                <tr><td>Western, Central, Southern</td><td>Same or next day</td><td>2 – 3 working days</td></tr>
                <tr><td>Other districts</td><td>Next working day</td><td>3 – 4 working days</td></tr>
                <tr><td>Remote / estate areas</td><td>Next working day</td><td>4 – 6 working days</td></tr>
              </tbody>
            </table>
          </div>
        </div>
        <div class="card-soft p-3 p-md-4">
          <h2 class="h6 fw-bold mb-2">How tracking works</h2>
          <p class="small text-ink-600 mb-2">Every order moves through a tracked pipeline: <strong>Pending → Confirmed → Packed → Shipped → Out for delivery → Delivered</strong>. The status changes the moment our team updates it, and the tracking page refreshes by itself while you watch.</p>
          <a class="btn btn-brand btn-sm" href="/track.php"><i class="bi bi-truck me-1"></i> Track my order</a>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card-soft p-3 p-md-4 mb-3">
          <h2 class="h6 fw-bold mb-2">Courier partners</h2>
          <ul class="small text-ink-600 mb-0" style="line-height:1.9">
            <li>Pronto Lanka</li>
            <li>Koombiyo Delivery</li>
            <li>Domex</li>
            <li>In-house rider (Colombo city)</li>
          </ul>
        </div>
        <div class="card-soft p-3 p-md-4">
          <h2 class="h6 fw-bold mb-2">Failed deliveries</h2>
          <p class="small text-ink-600 mb-0">Riders attempt delivery up to three times. If nobody is reachable the parcel returns to our warehouse and we contact you to arrange a re-dispatch, which may carry a second delivery fee.</p>
        </div>
      </div>
    </div>
  </div>
</section>
<?php endif; require INCLUDES_PATH . '/layout/footer.php'; ?>

<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$returnWindowDays = max(1, min(90, Settings::int('return_window_days', 7)));
$pageTitle = 'Returns & refunds';
$pageDescription = 'Our ' . $returnWindowDays . '-day returns policy, refund timelines and how to start a return.';
$heroTitle = 'Returns & refunds';
$heroSubtitle = 'Clear return terms and a real person to help.';
$heroIcon = 'bi-arrow-counterclockwise';
require INCLUDES_PATH . '/layout/header.php';
require INCLUDES_PATH . '/partials/page-hero.php';
?>
<?php if (Settings::get('returns_text') !== ''): ?>
<section class="section"><div class="container-page"><div class="row g-3"><div class="col-lg-8"><div class="card-soft p-3 p-md-4 mb-3" style="white-space:pre-line"><?= e(Settings::get('returns_text')) ?></div></div><div class="col-lg-4"><div class="card-soft p-3 p-md-4"><h2 class="h6 fw-bold mb-2">Need help?</h2><a class="btn btn-brand btn-sm w-100 mb-2" href="<?= Auth::check() ? '/account/returns.php' : '/login.php?next=%2Faccount%2Freturns.php' ?>">Request a return</a><a class="btn btn-soft btn-sm w-100" href="mailto:<?= e(Settings::get('support_email')) ?>">Contact support</a></div></div></div></div></section>
<?php else: ?>
<section class="section">
  <div class="container-page">
    <div class="row g-4">
      <div class="col-lg-8">
        <div class="card-soft p-3 p-md-4 mb-3">
          <h2 class="h6 fw-bold mb-2">Our return window</h2>
          <p class="small text-ink-600">You may return most items within <strong><?= (int) $returnWindowDays ?> days of delivery</strong> for a refund or exchange, provided they are unused, undamaged and in their original packaging with all accessories and manuals.</p>
          <ul class="small text-ink-600 mb-0" style="line-height:1.9">
            <li>Damaged or wrong item? Report within 48 hours with photos and we cover return shipping.</li>
            <li>Change of mind? Return shipping is on you, unless the item is faulty.</li>
            <li>Refunds go back to the original payment method, or as store credit if you prefer.</li>
          </ul>
        </div>
        <div class="card-soft p-3 p-md-4 mb-3">
          <h2 class="h6 fw-bold mb-2">What cannot be returned</h2>
          <ul class="small text-ink-600 mb-0" style="line-height:1.9">
            <li>Perishable groceries and opened food items</li>
            <li>Personal care, underwear and swimwear once opened</li>
            <li>Digital vouchers and activated gift cards</li>
            <li>Custom-built, cut-to-size or personalised products</li>
            <li>Items damaged by misuse or missing serial numbers</li>
          </ul>
        </div>
        <div class="card-soft p-3 p-md-4">
          <h2 class="h6 fw-bold mb-2">Refund timelines</h2>
          <div class="table-responsive">
            <table class="table table-admin mb-0 small">
              <thead><tr><th>Payment method</th><th>Refund route</th><th>Time</th></tr></thead>
              <tbody>
                <tr><td>Card (Seylan)</td><td>Back to card</td><td>5 – 10 working days</td></tr>
                <tr><td>Bank transfer</td><td>Bank account</td><td>2 – 4 working days</td></tr>
                <tr><td>Cash on delivery</td><td>Bank transfer or credit</td><td>2 – 4 working days</td></tr>
                <tr><td>KOKO / Payzy / Mintpay</td><td>Provider reverses the plan</td><td>Up to 14 working days</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card-soft p-3 p-md-4 mb-3">
          <h2 class="h6 fw-bold mb-2">Start a return</h2>
          <p class="small text-ink-500">Have your order number and a photo of the item ready.</p>
          <a class="btn btn-brand btn-sm w-100 mb-2" href="<?= Auth::check() ? '/account/returns.php' : '/login.php?next=%2Faccount%2Freturns.php' ?>">Request a return</a>
          <a class="btn btn-soft btn-sm w-100" href="<?= Auth::check() ? '/account/orders.php' : '/login.php?next=%2Faccount%2Forders.php' ?>">Find my order</a>
          <p class="small text-ink-500 mt-3 mb-0">Return requests are reviewed by our team. Shipping is arranged manually. Any approved refund or exchange is processed by the store; this website does not automatically reverse a card or bank payment.</p>
        </div>
        <div class="card-soft p-3 p-md-4">
          <h2 class="h6 fw-bold mb-2">Need help now?</h2>
          <a class="btn btn-soft btn-sm w-100 mb-2" href="tel:<?= e(preg_replace('/\s+/', '', Settings::get('support_phone'))) ?>"><i class="bi bi-telephone me-1"></i> <?= e(Settings::get('support_phone')) ?></a>
          <a class="btn btn-soft btn-sm w-100" href="mailto:<?= e(Settings::get('support_email')) ?>"><i class="bi bi-envelope me-1"></i> Email support</a>
        </div>
      </div>
    </div>
  </div>
</section>
<?php endif; require INCLUDES_PATH . '/layout/footer.php'; ?>

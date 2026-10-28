<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Terms of service';
$pageDescription = 'The terms that apply when you shop with ' . Settings::get('store_name') . '.';
$heroTitle = 'Terms of service';
$heroSubtitle = 'Last updated ' . fmt_date(date('Y-m-d'), 'j F Y');
$heroIcon = 'bi-file-text';
$acceptedPaymentMethods = array_map('payment_method_label', array_keys(PaymentManager::available(1)));
require INCLUDES_PATH . '/layout/header.php';
require INCLUDES_PATH . '/partials/page-hero.php';
?>
<?php if (Settings::get('terms_text') !== ''): ?>
<section class="section"><div class="container-page"><div class="row justify-content-center"><div class="col-lg-9"><div class="card-soft p-3 p-md-4" style="white-space:pre-line"><?= e(Settings::get('terms_text')) ?></div><p class="small text-ink-500 mt-3">Questions? <a href="mailto:<?= e(Settings::get('support_email')) ?>"><?= e(Settings::get('support_email')) ?></a></p></div></div></div></section>
<?php else: ?>
<section class="section">
  <div class="container-page">
    <div class="row justify-content-center">
      <div class="col-lg-9">
        <div class="card-soft p-3 p-md-4 policy-copy small text-ink-700">
          <?php
          $sections = [
              '1. Who we are' => Settings::get('store_name') . ' ("we", "us") is a single-vendor online store operating in Sri Lanka from ' . Settings::get('address') . '. By placing an order you agree to these terms.',
              '2. Your account' => 'You must supply accurate contact and delivery information and keep your password confidential. You are responsible for activity on your account. Guest checkout is available, but an account is needed to re-download invoices and use the wishlist.',
              '3. Products and pricing' => 'Prices are shown in ' . Settings::get('currency', 'LKR') . ' (' . Settings::get('currency_symbol', 'Rs') . ') and include applicable taxes unless stated otherwise. We try to keep stock and pricing accurate; if something is mispriced or unavailable we will contact you before charging and offer a substitute, a delay or a full refund.',
              '4. Orders and acceptance' => 'An order is an offer to buy. It is accepted when we confirm it — usually within a few hours — and stock is reserved at that point. We may refuse or cancel an order if fraud is suspected, stock is unavailable, or delivery is not possible.',
              '5. Payment' => 'Payment methods currently enabled at checkout include ' . ($acceptedPaymentMethods !== [] ? implode(', ', $acceptedPaymentMethods) : 'the options shown on the checkout page') . '. Payment provider details are entered on the provider\'s secure page; we do not store full card numbers.',
              '6. Delivery' => 'Delivery timelines are estimates, not guarantees. Risk passes to you on delivery. Please inspect parcels on arrival and report visible damage within 48 hours.',
              '7. Returns and refunds' => 'Our returns policy forms part of these terms. Refunds are issued to the original payment method once the returned item has been inspected.',
              '8. Warranty' => 'Manufacturer and local warranties are provided by the brand or its authorised agent. Your invoice is the proof of purchase.',
              '9. Acceptable use' => 'You must not use this site to break the law, scrape content at scale, attempt to access other customers\' data, or interfere with the operation of the service.',
              '10. Liability' => 'To the extent permitted by law, our liability for any order is limited to the amount you paid for it. Nothing in these terms limits rights you have under the Consumer Affairs Authority Act.',
              '11. Changes' => 'We may update these terms. The version in force is the one published on this page when you place your order.',
              '12. Governing law' => 'These terms are governed by the laws of Sri Lanka and the courts of Colombo have jurisdiction.',
          ];
          foreach ($sections as $heading => $body): ?>
            <h2 class="h6 fw-bold mt-3"><?= e($heading) ?></h2>
            <p class="mb-2"><?= e($body) ?></p>
          <?php endforeach; ?>
          <p class="text-ink-500 mt-3 mb-0">Questions about these terms? Email <a href="mailto:<?= e(Settings::get('support_email')) ?>"><?= e(Settings::get('support_email')) ?></a>.</p>
        </div>
      </div>
    </div>
  </div>
</section>
<?php endif; require INCLUDES_PATH . '/layout/footer.php'; ?>

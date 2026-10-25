<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Help centre';
$pageDescription = 'Answers about orders, delivery, payments and returns.';
$heroTitle = 'Help centre';
$heroSubtitle = 'Everything you need to know about ordering, paying and getting your parcel.';
$heroIcon = 'bi-life-preserver';
require INCLUDES_PATH . '/layout/header.php';
require INCLUDES_PATH . '/partials/page-hero.php';
?>
<section class="section">
  <div class="container-page">
    <div class="row g-4">
      <div class="col-lg-8">
        <div class="accordion" id="helpAccordion">
          <?php
          $topics = [
              ['How long does delivery take?', 'In-stock orders placed before 2pm on a weekday are dispatched the same day. Colombo and Gampaha usually arrive in 1–2 working days, other districts in 2–4, and remote areas in 4–6. You can follow every step from the tracking page.'],
              ['Which payment methods do you accept?', 'Cash on delivery, Visa/Mastercard/Amex via Seylan Bank, direct bank transfer to Seylan Bank or Commercial Bank, and buy-now-pay-later with KOKO, Payzy and Mintpay.'],
              ['Is cash on delivery available everywhere?', 'Yes — island-wide, up to an order value of Rs 150,000. Please have the exact amount ready for the rider.'],
              ['How do I return an item?', 'Contact us within 7 days of delivery with your order number. Items must be unused and in original packaging. We arrange a pickup or ask you to drop the parcel at our Colombo office.'],
              ['Can I change my delivery address after ordering?', 'If the order has not shipped yet, message us with the order number and we will update it. Once dispatched, changes go through the courier and may add a day.'],
              ['Do you offer warranties?', 'Products that carry a local warranty show it on the product page. Keep your invoice — it is your warranty proof and can be downloaded from your account.'],
              ['Do I need an account to order?', 'No, guest checkout is enabled. An account lets you save addresses, track orders in one place and collect wishlist items.'],
              ['How do I use a coupon?', 'Enter the code in the coupon box on the cart or checkout page and press Apply. One coupon per order; some codes have a minimum spend.'],
          ];
          foreach ($topics as $index => [$question, $answer]): ?>
            <div class="accordion-item border-0 border-bottom">
              <h2 class="accordion-header">
                <button class="accordion-button <?= $index === 0 ? '' : 'collapsed' ?> px-0 shadow-none bg-transparent fw-semibold small" type="button" data-bs-toggle="collapse" data-bs-target="#help-<?= $index ?>" aria-expanded="<?= $index === 0 ? 'true' : 'false' ?>">
                  <?= e($question) ?>
                </button>
              </h2>
              <div id="help-<?= $index ?>" class="accordion-collapse collapse <?= $index === 0 ? 'show' : '' ?>" data-bs-parent="#helpAccordion">
                <div class="accordion-body px-0 small text-ink-600"><?= e($answer) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="card-soft p-3 p-md-4 mt-4" id="payments">
          <h2 class="h6 fw-bold mb-2">Payment options in detail</h2>
          <div class="row g-2">
            <?php foreach (PaymentManager::gateways() as $code => $gateway): ?>
              <div class="col-sm-6">
                <div class="border rounded-3 p-2 h-100 <?= $gateway->enabled() ? '' : 'opacity-50' ?>">
                  <div class="d-flex align-items-center gap-2 mb-1">
                    <i class="bi <?= e(payment_method_icon($code)) ?> text-brand"></i>
                    <strong class="small"><?= e(payment_method_label($code)) ?></strong>
                    <?php if (!$gateway->enabled()): ?><span class="badge text-bg-light">Coming soon</span><?php endif; ?>
                  </div>
                  <p class="text-ink-500 mb-0" style="font-size:.74rem"><?= e($gateway->description()) ?></p>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card-soft p-3 mb-3">
          <h2 class="h6 fw-bold mb-2">Still stuck?</h2>
          <p class="small text-ink-500">Our team is available 9am–6pm Monday to Saturday.</p>
          <a class="btn btn-brand btn-sm w-100 mb-2" href="/contact.php">Message support</a>
          <a class="btn btn-soft btn-sm w-100" href="tel:<?= e(preg_replace('/\s+/', '', Settings::get('support_phone'))) ?>"><i class="bi bi-telephone me-1"></i> <?= e(Settings::get('support_phone')) ?></a>
        </div>
        <div class="card-soft p-3">
          <h2 class="h6 fw-bold mb-2">Policies</h2>
          <div class="d-grid gap-2">
            <a class="btn btn-soft btn-sm text-start" href="/shipping.php"><i class="bi bi-truck me-2"></i>Shipping &amp; delivery</a>
            <a class="btn btn-soft btn-sm text-start" href="/returns.php"><i class="bi bi-arrow-counterclockwise me-2"></i>Returns &amp; refunds</a>
            <a class="btn btn-soft btn-sm text-start" href="/terms.php"><i class="bi bi-file-text me-2"></i>Terms of service</a>
            <a class="btn btn-soft btn-sm text-start" href="/privacy.php"><i class="bi bi-shield-lock me-2"></i>Privacy policy</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

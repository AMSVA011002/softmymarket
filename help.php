<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Help centre';
$pageDescription = 'Answers about orders, delivery, payments and returns.';
$customFaqs = trim(Settings::get('faq_items'));
$topics = [];
if ($customFaqs !== '') {
    foreach (preg_split('/\\R/u', $customFaqs) ?: [] as $line) {
        $pair = explode('|', trim($line), 2);
        if (count($pair) === 2 && trim($pair[0]) !== '' && trim($pair[1]) !== '') {
            $topics[] = [trim($pair[0]), trim($pair[1])];
            if (count($topics) >= 20) break;
        }
    }
}
if ($topics === []) {
    $methods = array_map('payment_method_label', array_keys(PaymentManager::available(1)));
    $returnDays = max(1, min(90, Settings::int('return_window_days', 7)));
    $topics = [
        ['How long does delivery take?', 'Typical delivery time: ' . Settings::get('delivery_promise') . '. See the Shipping & delivery page for the current rates and details.'],
        ['Which payment methods do you accept?', $methods !== [] ? 'Currently available at checkout: ' . implode(', ', $methods) . '.' : 'Payment methods are shown at checkout.'],
        ['Is cash on delivery available?', isset(PaymentManager::available(1)['COD']) ? 'Cash on delivery is available for eligible orders; the checkout page shows whether it applies to your order.' : 'Cash on delivery is not currently enabled. Other available methods appear at checkout.'],
        ['How do I return an item?', 'Submit a return request within ' . $returnDays . ' days of delivery from your account. See the Returns & refunds page for the terms.'],
        ['Can I change my delivery address after ordering?', 'Contact support as soon as possible with your order number. Address changes may not be possible after dispatch.'],
        ['Do products include a warranty?', 'Warranty information is shown on individual product listings where applicable. Contact support if you need help.'],
        ['Do I need an account to order?', Settings::bool('allow_guest_checkout', true) ? 'No, guest checkout is available. An account also lets you save addresses and manage orders.' : 'Yes. Sign in or create an account before checkout.'],
        ['How do I use a coupon?', 'Enter your code in the coupon field on the cart or checkout page. Coupon conditions are shown when you apply it.'],
    ];
}
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
          <?php foreach ($topics as $index => [$question, $answer]): ?>
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

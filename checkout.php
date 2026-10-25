<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

// "Buy now" adds a single product and continues straight to checkout.
$buyNow = query_int('buy');
if ($buyNow > 0) {
    $added = Cart::add($buyNow, 1);
    if (!$added['ok']) {
        flash('danger', $added['error'] ?? 'That product is not available.');
        redirect('/product.php?id=' . $buyNow);
    }
    redirect('/checkout.php');
}

// "Buy now" on the product page posts here so the cart never needs JavaScript.
if (is_post() && (string) post('intent', '') === 'buy') {
    Csrf::verifyRequest();
    RateLimiter::guard('checkout');
    $added = Cart::add(post_int('product_id'), max(1, post_int('quantity', 1)));
    if (!$added['ok']) {
        flash('danger', $added['error'] ?? 'That product is not available.');
        redirect('/shop.php');
    }
    redirect('/checkout.php');
}

$cart = Cart::contents();
if ($cart['items'] === []) {
    flash('info', 'Your cart is empty — add something first.');
    redirect('/cart.php');
}
$couponCode = (string) ($_SESSION['coupon_code'] ?? '');
$coupon = null;
if ($couponCode !== '') {
    $check = Coupons::validate($couponCode, ['subtotal' => $cart['subtotal'], 'user_id' => Auth::id(), 'items' => $cart['items']]);
    if ($check['valid']) {
        $coupon = $check['coupon'] + ['discount' => $check['discount'], 'free_shipping' => $check['free_shipping']];
    }
}
$quote = Pricing::quote($cart, $coupon);

$user = Auth::user();
$addresses = $user !== null ? Addresses::forUser((int) $user['id']) : [];
$defaultAddress = $addresses[0] ?? null;

$districts = [
    'Colombo', 'Gampaha', 'Kalutara', 'Galle', 'Matara', 'Hambantota', 'Jaffna', 'Kilinochchi',
    'Mannar', 'Vavuniya', 'Mullaitivu', 'Batticaloa', 'Ampara', 'Trincomalee', 'Kurunegala',
    'Puttalam', 'Anuradhapura', 'Polonnaruwa', 'Matale', 'Kandy', 'Nuwara Eliya', 'Kegalle',
    'Ratnapura', 'Badulla', 'Monaragala',
];

$gateways = PaymentManager::available($quote['total']);

$pageTitle = 'Checkout';
$pageDescription = 'Secure checkout with cash on delivery, cards, bank transfer and pay-later options.';
$noindex = true;
require INCLUDES_PATH . '/layout/header.php';
?>
<div class="breadcrumb-wrap"><div class="container-page"><?= View::breadcrumbs(['Cart' => '/cart.php', 'Checkout' => null]) ?></div></div>

<section class="section pt-2">
  <div class="container-page">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
      <h1 class="h4 fw-bold mb-0">Checkout</h1>
      <div class="steps small">
        <span class="done"><i class="bi bi-check-circle-fill"></i> Cart</span>
        <span class="current"><i class="bi bi-2-circle-fill"></i> Details &amp; payment</span>
        <span><i class="bi bi-3-circle"></i> Confirmation</span>
      </div>
    </div>

    <?php if ($user === null): ?>
      <div class="alert alert-info small d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span><i class="bi bi-info-circle me-1"></i> Checking out as a guest. <a href="/login.php?next=/checkout.php">Sign in</a> to use saved addresses and track orders in one place.</span>
      </div>
    <?php endif; ?>

    <form method="post" action="/actions/checkout.php" class="row g-4" id="checkoutForm" data-disable-on-submit>
      <?= Csrf::field() ?>
      <div class="col-lg-7">
        <!-- Contact -->
        <div class="card-soft p-3 p-md-4 mb-3">
          <h2 class="h6 fw-bold mb-3"><span class="step-badge">1</span> Contact details</h2>
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label" for="customer_name">Full name</label>
              <input class="form-control" id="customer_name" name="customer_name" required maxlength="120" autocomplete="name"
                     value="<?= e(old('customer_name', $user['name'] ?? ($defaultAddress['full_name'] ?? ''))) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="phone">Mobile number</label>
              <input class="form-control" id="phone" name="phone" required maxlength="20" inputmode="tel" autocomplete="tel"
                     placeholder="077 123 4567" value="<?= e(old('phone', $user['phone'] ?? ($defaultAddress['phone'] ?? ''))) ?>">
            </div>
            <div class="col-12">
              <label class="form-label" for="email">Email</label>
              <input class="form-control" id="email" type="email" name="email" required maxlength="160" autocomplete="email"
                     value="<?= e(old('email', $user['email'] ?? '')) ?>">
              <div class="form-text">Your receipt and tracking link are sent here.</div>
            </div>
          </div>
        </div>

        <!-- Delivery -->
        <div class="card-soft p-3 p-md-4 mb-3">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h6 fw-bold mb-0"><span class="step-badge">2</span> Delivery address</h2>
            <?php if ($user !== null): ?><a class="small text-decoration-none" href="/account/addresses.php"><i class="bi bi-plus-lg"></i> Manage addresses</a><?php endif; ?>
          </div>

          <?php if ($addresses !== []): ?>
            <div class="saved-address mb-3">
              <?php foreach ($addresses as $index => $address): ?>
                <label class="saved-address-option d-flex gap-2">
                  <input class="form-check-input mt-1" type="radio" name="address_id" value="<?= (int) $address['id'] ?>" <?= $index === 0 ? 'checked' : '' ?>
                         data-address='<?= e(json_encode([
                             'full_name' => $address['full_name'], 'phone' => $address['phone'], 'line1' => $address['line1'],
                             'line2' => $address['line2'], 'city' => $address['city'], 'district' => $address['district'],
                             'postal_code' => $address['postal_code'],
                         ], JSON_UNESCAPED_UNICODE)) ?>'>
                  <span class="small">
                    <strong><?= e($address['label']) ?></strong><?= (int) $address['is_default'] === 1 ? ' <span class="badge text-bg-success">Default</span>' : '' ?><br>
                    <?= e($address['full_name']) ?> · <?= e(phone_display((string) $address['phone'])) ?><br>
                    <?= e($address['line1']) ?><?= $address['line2'] ? ', ' . e($address['line2']) : '' ?>, <?= e($address['city']) ?>, <?= e($address['district']) ?>
                  </span>
                </label>
              <?php endforeach; ?>
              <label class="saved-address-option d-flex gap-2">
                <input class="form-check-input mt-1" type="radio" name="address_id" value="" <?= $addresses === [] ? 'checked' : '' ?>>
                <span class="small"><strong>Use a different address</strong><br>Enter the details below.</span>
              </label>
            </div>
          <?php endif; ?>

          <div class="row g-2">
            <div class="col-12">
              <label class="form-label" for="line1">Address line 1</label>
              <input class="form-control" id="line1" name="line1" required maxlength="180" autocomplete="address-line1"
                     value="<?= e(old('line1', $defaultAddress['line1'] ?? '')) ?>">
            </div>
            <div class="col-12">
              <label class="form-label" for="line2">Address line 2 <span class="text-ink-400">(optional)</span></label>
              <input class="form-control" id="line2" name="line2" maxlength="180" autocomplete="address-line2"
                     value="<?= e(old('line2', $defaultAddress['line2'] ?? '')) ?>">
            </div>
            <div class="col-md-5">
              <label class="form-label" for="city">City / town</label>
              <input class="form-control" id="city" name="city" required maxlength="80" autocomplete="address-level2"
                     value="<?= e(old('city', $defaultAddress['city'] ?? '')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="district">District</label>
              <select class="form-select" id="district" name="district" required>
                <option value="">Select district</option>
                <?php foreach ($districts as $district): ?>
                  <option value="<?= e($district) ?>" <?= old('district', $defaultAddress['district'] ?? '') === $district ? 'selected' : '' ?>><?= e($district) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label" for="postal_code">Postal code</label>
              <input class="form-control" id="postal_code" name="postal_code" maxlength="20" autocomplete="postal-code"
                     value="<?= e(old('postal_code', $defaultAddress['postal_code'] ?? '')) ?>">
            </div>
            <div class="col-12">
              <label class="form-label" for="note">Delivery note <span class="text-ink-400">(optional)</span></label>
              <textarea class="form-control" id="note" name="note" rows="2" maxlength="500" placeholder="Landmark, gate code or preferred delivery time"><?= e(old('note', '')) ?></textarea>
            </div>
          </div>
        </div>

        <!-- Payment -->
        <div class="card-soft p-3 p-md-4 mb-3">
          <h2 class="h6 fw-bold mb-1"><span class="step-badge">3</span> Payment method</h2>
          <p class="small text-ink-500 mb-3">All card payments are handled by the bank. We never see or store your card details.</p>

          <div class="row g-2">
            <?php $first = true; foreach ($gateways as $code => $gateway):
                $summary = Pricing::quote($cart, $coupon, $code); ?>
              <div class="col-md-6">
                <label class="payment-option <?= $first ? 'selected' : '' ?>" data-payment-option>
                  <input class="form-check-input" type="radio" name="payment_method" value="<?= e($code) ?>" <?= $first ? 'checked' : '' ?> required>
                  <span class="payment-option-body">
                    <span class="payment-option-title"><i class="bi <?= e(payment_method_icon($code)) ?>"></i> <?= e(payment_method_label($code)) ?></span>
                    <span class="payment-option-desc"><?= e($gateway->description()) ?></span>
                    <?php if ($summary['shipping'] !== $quote['shipping']): ?>
                      <span class="payment-option-total">Total <?= e(money($summary['total'])) ?></span>
                    <?php endif; ?>
                    <span class="payment-option-sim d-none"><i class="bi bi-cone-striped"></i> Test mode until live credentials are added</span>
                  </span>
                </label>
              </div>
              <?php $first = false; endforeach; ?>
          </div>

          <?php if ($gateways === []): ?>
            <div class="alert alert-danger small mb-0">No payment method is available for this order. Please contact support.</div>
          <?php endif; ?>

          <div class="mt-3 small text-ink-600 d-none" id="bankDetails">
            <div class="card-soft p-3">
              <strong class="d-block mb-1"><i class="bi bi-bank me-1"></i> Bank transfer instructions</strong>
              <?= e(Settings::get('bank_transfer_note')) ?>
              <div class="mt-2">Your order will be confirmed once our finance team verifies the deposit.</div>
            </div>
          </div>
        </div>

        <div class="card-soft p-3">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="terms" name="terms" value="1" required>
            <label class="form-check-label small" for="terms">
              I agree to the <a href="/terms.php" target="_blank" rel="noopener">terms of service</a>,
              <a href="/privacy.php" target="_blank" rel="noopener">privacy policy</a> and
              <a href="/returns.php" target="_blank" rel="noopener">returns policy</a>.
            </label>
          </div>
          <div class="d-grid d-md-flex gap-2 mt-3">
            <button class="btn btn-brand btn-lg flex-grow-1" type="submit" <?= $gateways === [] ? 'disabled' : '' ?>>
              <i class="bi bi-lock me-1"></i> Place order · <?= e(money($quote['total'])) ?>
            </button>
            <a class="btn btn-soft btn-lg" href="/cart.php">Back to cart</a>
          </div>
          <p class="small text-ink-400 mt-2 mb-0"><i class="bi bi-shield-lock"></i> Your details are encrypted in transit and never sold.</p>
        </div>
      </div>

      <!-- Summary -->
      <div class="col-lg-5">
        <div class="card-soft p-3 p-md-4 sticky-sidebar">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h2 class="h6 fw-bold mb-0">Order summary</h2>
            <a class="small text-decoration-none" href="/cart.php">Edit cart</a>
          </div>
          <div class="checkout-items mb-2">
            <?php foreach ($cart['items'] as $line): $product = $line['product']; ?>
              <div class="d-flex gap-2 py-2 border-bottom align-items-center">
                <img src="<?= e(product_image($product)) ?>" alt="" width="52" height="52" style="object-fit:cover;border-radius:10px" loading="lazy">
                <div class="flex-grow-1 min-w-0">
                  <div class="small fw-semibold text-truncate"><?= e($product['title']) ?></div>
                  <div class="text-ink-500" style="font-size:.74rem">Qty <?= (int) $line['quantity'] ?> · <?= e(money($product['price'])) ?></div>
                </div>
                <div class="small fw-semibold"><?= e(money($line['line_total'])) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="summary-row"><span>Subtotal</span><span><?= e(money($quote['subtotal'])) ?></span></div>
          <?php if ($quote['discount'] > 0): ?><div class="summary-row text-brand"><span>Discount <?= $coupon !== null ? '(' . e($coupon['code']) . ')' : '' ?></span><span>−<?= e(money($quote['discount'])) ?></span></div><?php endif; ?>
          <div class="summary-row"><span>Delivery</span><span><?= $quote['shipping'] > 0 ? e(money($quote['shipping'])) : '<span class="text-brand fw-semibold">Free</span>' ?></span></div>
          <?php if ($quote['tax'] > 0): ?><div class="summary-row"><span>Tax</span><span><?= e(money($quote['tax'])) ?></span></div><?php endif; ?>
          <div class="summary-row total"><span>Total to pay</span><span><?= e(money($quote['total'])) ?></span></div>

          <div class="coupon-strip mt-3">
            <div class="small">
              <?php if ($coupon !== null): ?>
                <i class="bi bi-ticket-perforated-fill text-brand"></i> <strong><?= e($coupon['code']) ?></strong> applied
              <?php else: ?>
                <i class="bi bi-ticket-perforated text-brand"></i> Have a coupon? Apply it in the <a href="/cart.php">cart</a>.
              <?php endif; ?>
            </div>
          </div>

          <ul class="list-unstyled small text-ink-500 mt-3 mb-0">
            <li class="mb-1"><i class="bi bi-truck text-brand me-1"></i> Estimated delivery: <?= e(fmt_date(date('Y-m-d', strtotime('+3 days')), 'D j M')) ?> – <?= e(fmt_date(date('Y-m-d', strtotime('+5 days')), 'D j M')) ?></li>
            <li class="mb-1"><i class="bi bi-box-seam text-brand me-1"></i> Dispatch within 24 hours on weekdays</li>
            <li><i class="bi bi-arrow-repeat text-brand me-1"></i> 7-day easy returns</li>
          </ul>
        </div>
      </div>
    </form>
  </div>
</section>

<script nonce="<?= e(Security::nonce()) ?>">
  document.querySelectorAll('input[name="address_id"]').forEach(function (radio) {
    radio.addEventListener('change', function () {
      if (!radio.checked) return;
      var raw = radio.getAttribute('data-address');
      if (!raw) return;
      try {
        var data = JSON.parse(raw);
        ['full_name', 'phone', 'line1', 'line2', 'city', 'district', 'postal_code'].forEach(function (key) {
          var field = document.getElementById(key);
          if (field && data[key] !== undefined && data[key] !== null) field.value = data[key];
        });
      } catch (error) { /* ignore malformed payloads */ }
    });
  });
  document.querySelectorAll('input[name="payment_method"]').forEach(function (input) {
    var sync = function () {
      var box = document.getElementById('bankDetails');
      if (box) box.classList.toggle('d-none', input.value !== 'BANK_TRANSFER' || !input.checked);
      document.querySelectorAll('.payment-option-sim').forEach(function (el) {
        var option = el.closest('.payment-option');
        var radio = option ? option.querySelector('input[name="payment_method"]') : null;
        el.classList.toggle('d-none', !(radio && radio.checked && el.closest('[data-gateway-sim]')));
      });
    };
    input.addEventListener('change', sync);
  });
</script>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

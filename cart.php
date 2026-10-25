<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$cart = Cart::contents();
$couponCode = (string) ($_SESSION['coupon_code'] ?? '');
$coupon = null;
if ($couponCode !== '') {
    $check = Coupons::validate($couponCode, [
        'subtotal' => $cart['subtotal'],
        'user_id'  => Auth::id(),
        'items'    => $cart['items'],
    ]);
    if ($check['valid']) {
        $coupon = $check['coupon'] + ['discount' => $check['discount'], 'free_shipping' => $check['free_shipping']];
    } else {
        unset($_SESSION['coupon_code']);
        flash('warning', $check['message']);
    }
}
$quote = Pricing::quote($cart, $coupon);
$toFreeShipping = Pricing::amountToFreeShipping($quote['subtotal']);
$recommended = Catalog::bestSellers(8);

$pageTitle = 'Your cart';
$noindex = true;
require INCLUDES_PATH . '/layout/header.php';
?>
<div class="breadcrumb-wrap"><div class="container-page"><?= View::breadcrumbs(['Your cart' => null]) ?></div></div>

<section class="section pt-2">
  <div class="container-page">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-3">
      <div>
        <h1 class="h4 fw-bold mb-1">Your cart</h1>
        <p class="small text-ink-500 mb-0"><?= (int) $quote['item_count'] ?> item<?= $quote['item_count'] === 1 ? '' : 's' ?> · prices include VAT where applicable</p>
      </div>
      <a class="btn btn-soft btn-sm" href="/shop.php"><i class="bi bi-arrow-left me-1"></i>Continue shopping</a>
    </div>

    <?php if ($cart['items'] === []): ?>
      <?= View::emptyState('bi-bag', 'Your cart is empty', 'Find something you love — over 10,000 products with island-wide delivery.', 'Browse products', '/shop.php') ?>
    <?php else: ?>
      <div class="row g-4">
        <div class="col-lg-8">
          <?php if ($cart['removed'] !== []): ?>
            <div class="alert alert-warning small">Some items were removed because they went out of stock: <?= e(implode(', ', $cart['removed'])) ?></div>
          <?php endif; ?>

          <?php if ($toFreeShipping > 0): ?>
            <div class="free-ship-track mb-3">
              <div class="d-flex justify-content-between small mb-1">
                <span>Add <strong><?= e(money($toFreeShipping)) ?></strong> more for free delivery</span>
                <span><?= e(money($quote['subtotal'])) ?> / <?= e(money(Settings::float('free_shipping_threshold', 15000))) ?></span>
              </div>
              <?php $pct = min(100, (int) round(($quote['subtotal'] / max(1, Settings::float('free_shipping_threshold', 15000))) * 100)); ?>
              <div class="track-progress"><div style="width:<?= $pct ?>%"></div></div>
            </div>
          <?php else: ?>
            <div class="alert alert-success small d-flex align-items-center gap-2 mb-3"><i class="bi bi-truck"></i> You have unlocked free delivery.</div>
          <?php endif; ?>

          <div class="card-soft p-2 p-md-3 mb-3">
            <?php foreach ($cart['items'] as $line): $product = $line['product']; ?>
              <div class="cart-row d-flex gap-3 py-3 border-bottom">
                <a href="/product.php?slug=<?= e($product['slug']) ?>" class="flex-shrink-0">
                  <img src="<?= e(product_image($product)) ?>" alt="<?= e($product['title']) ?>" width="92" height="92" style="object-fit:cover;border-radius:12px" loading="lazy">
                </a>
                <div class="flex-grow-1 min-w-0">
                  <div class="d-flex justify-content-between gap-2">
                    <a class="fw-semibold text-decoration-none text-ink-900 text-truncate-2" href="/product.php?slug=<?= e($product['slug']) ?>"><?= e($product['title']) ?></a>
                    <button class="btn btn-link btn-sm text-danger text-decoration-none p-0" type="button" data-cart-remove="<?= (int) $product['id'] ?>" aria-label="Remove <?= e($product['title']) ?>"><i class="bi bi-x-lg"></i></button>
                  </div>
                  <div class="small text-ink-500 mb-1">
                    SKU <?= e($product['sku']) ?> · <?= e($product['category_name'] ?? '') ?>
                    <?php if ($product['stock'] <= 5): ?><span class="text-danger"> · only <?= (int) $product['stock'] ?> left</span><?php endif; ?>
                  </div>
                  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div class="qty-stepper">
                      <button type="button" data-qty-step="-1" aria-label="Decrease quantity"><i class="bi bi-dash"></i></button>
                      <input type="number" value="<?= (int) $line['quantity'] ?>" min="1" max="<?= (int) $product['stock'] ?>" data-cart-qty="<?= (int) $product['id'] ?>" aria-label="Quantity for <?= e($product['title']) ?>">
                      <button type="button" data-qty-step="1" aria-label="Increase quantity"><i class="bi bi-plus"></i></button>
                    </div>
                    <div class="text-end">
                      <div class="fw-bold"><?= e(money($line['line_total'])) ?></div>
                      <div class="small text-ink-500"><?= e(money($product['price'])) ?> each</div>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>

            <div class="d-flex flex-wrap justify-content-between gap-2 pt-3">
              <form method="post" action="/actions/cart.php" data-confirm="Remove every item from your cart?">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="clear">
                <button class="btn btn-soft btn-sm" type="submit"><i class="bi bi-trash me-1"></i>Clear cart</button>
              </form>
              <a class="btn btn-soft btn-sm" href="/shop.php"><i class="bi bi-plus-lg me-1"></i>Add more items</a>
            </div>
          </div>

          <div class="card-soft p-3">
            <h2 class="h6 fw-bold mb-2"><i class="bi bi-ticket-perforated text-brand me-1"></i> Have a coupon?</h2>
            <?php if ($coupon !== null): ?>
              <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div class="coupon-chip">
                  <i class="bi bi-check-circle-fill"></i> <?= e($coupon['code']) ?>
                  <span class="small">— <?= e($coupon['type'] === 'free_shipping' ? 'Free delivery' : money($coupon['discount']) . ' off') ?></span>
                </div>
                <form method="post" action="/actions/cart.php">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="coupon_remove">
                  <button class="btn btn-soft btn-sm" type="submit">Remove</button>
                </form>
              </div>
            <?php else: ?>
              <form method="post" action="/actions/cart.php" class="d-flex gap-2 flex-wrap">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="coupon_apply">
                <label class="visually-hidden" for="coupon">Coupon code</label>
                <input class="form-control form-control-sm" id="coupon" name="code" placeholder="Enter code" maxlength="40" style="max-width:240px" required>
                <button class="btn btn-outline-brand btn-sm" type="submit">Apply</button>
                <?php $active = Coupons::active(3); ?>
                <?php if ($active !== []): ?>
                  <div class="w-100 small text-ink-500 mt-1">Try <?= e(implode(', ', array_map(static fn ($c) => $c['code'], $active))) ?></div>
                <?php endif; ?>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <div class="col-lg-4">
          <div class="card-soft p-3 p-md-4 sticky-sidebar">
            <h2 class="h6 fw-bold mb-3">Order summary</h2>
            <div class="summary-row"><span>Subtotal (<?= (int) $quote['item_count'] ?> items)</span><span><?= e(money($quote['subtotal'])) ?></span></div>
            <?php if ($quote['discount'] > 0): ?>
              <div class="summary-row text-brand"><span>Discount <?= $coupon !== null ? '(' . e($coupon['code']) . ')' : '' ?></span><span>−<?= e(money($quote['discount'])) ?></span></div>
            <?php endif; ?>
            <div class="summary-row"><span>Delivery</span><span><?= $quote['shipping'] > 0 ? e(money($quote['shipping'])) : '<span class="text-brand fw-semibold">Free</span>' ?></span></div>
            <?php if ($quote['tax'] > 0): ?><div class="summary-row"><span>Tax</span><span><?= e(money($quote['tax'])) ?></span></div><?php endif; ?>
            <div class="summary-row total"><span>Total</span><span><?= e(money($quote['total'])) ?></span></div>
            <div class="d-grid gap-2 mt-3">
              <a class="btn btn-brand btn-lg" href="/checkout.php">Proceed to checkout <i class="bi bi-arrow-right"></i></a>
              <a class="btn btn-soft" href="/shop.php">Keep shopping</a>
            </div>
            <ul class="list-unstyled small text-ink-500 mt-3 mb-0">
              <li class="mb-1"><i class="bi bi-shield-check text-brand me-1"></i> Secure checkout, encrypted end to end</li>
              <li class="mb-1"><i class="bi bi-truck text-brand me-1"></i> Dispatch within 24 hours on weekdays</li>
              <li><i class="bi bi-arrow-repeat text-brand me-1"></i> 7-day returns on most items</li>
            </ul>
            <div class="d-flex flex-wrap gap-1 mt-3">
              <?php foreach (array_keys(PaymentManager::gateways()) as $code): ?>
                <span class="pay-badge"><i class="bi <?= e(payment_method_icon($code)) ?>"></i><?= e(payment_method_label($code)) ?></span>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php if ($recommended !== []): ?>
  <section class="section pt-0">
    <div class="container-page">
      <div class="section-head">
        <div><h2 class="h5 fw-bold mb-1">Frequently bought together</h2><p class="small text-ink-500 mb-0">Popular picks from other shoppers</p></div>
        <a class="small text-decoration-none" href="/shop.php?sort=popular">See all <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="grid-products">
        <?php foreach ($recommended as $product): ?><?= View::card($product) ?><?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

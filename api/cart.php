<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (is_post()) {
    Csrf::verifyRequest();
    RateLimiter::guard('api');
    $action = (string) request_input('action', '');
    $productId = (int) (request_input('product_id', '0'));
    $quantity = (int) (request_input('quantity', '1'));

    switch ($action) {
        case 'add':
            $result = Cart::add($productId, max(1, $quantity));
            break;
        case 'update':
            $result = Cart::update($productId, $quantity);
            break;
        case 'remove':
            Cart::remove($productId);
            $result = ['ok' => true, 'message' => 'Removed from cart.'];
            break;
        case 'clear':
            Cart::clear();
            $result = ['ok' => true, 'message' => 'Cart cleared.'];
            break;
        default:
            json_response(['error' => 'Unknown cart action.'], 422);
    }

    if (!$result['ok']) {
        json_response(array_merge(['error' => $result['error'] ?? 'Request failed'], ['count' => Cart::count()]), 422);
    }
    json_response([
        'ok' => true,
        'message' => $result['message'] ?? 'Cart updated.',
        'count' => Cart::count(),
        'quantity' => $result['quantity'] ?? null,
    ]);
}

// GET: drawer contents or a lightweight count.
$cart = Cart::contents();
$coupon = null;
if (!empty($_SESSION['coupon_code'])) {
    $check = Coupons::validate((string) $_SESSION['coupon_code'], [
        'subtotal' => $cart['subtotal'],
        'user_id' => Auth::id(),
        'items' => $cart['items'],
    ]);
    if ($check['valid']) {
        $coupon = $check['coupon'] + ['discount' => $check['discount'], 'free_shipping' => $check['free_shipping']];
    }
}
$quote = Pricing::quote($cart, $coupon);

if (query('view') !== 'drawer') {
    json_response([
        'count' => $cart['count'],
        'items' => array_map(static fn ($line) => [
            'id' => (int) $line['product']['id'],
            'title' => $line['product']['title'],
            'quantity' => (int) $line['quantity'],
            'price' => (float) $line['product']['price'],
            'total' => (float) $line['line_total'],
        ], $cart['items']),
        'subtotal' => $quote['subtotal'],
        'shipping' => $quote['shipping'],
        'discount' => $quote['discount'],
        'total' => $quote['total'],
    ]);
}

ob_start();
?>
<?php if ($cart['items'] === []): ?>
  <div class="text-center py-5">
    <i class="bi bi-bag" style="font-size:2.4rem;color:var(--ink-300)"></i>
    <p class="small text-ink-500 mt-2 mb-3">Your cart is empty.</p>
    <a class="btn btn-brand btn-sm" href="/shop.php">Start shopping</a>
  </div>
<?php else: ?>
  <div class="flex-grow-1" style="overflow:auto">
    <?php foreach ($cart['items'] as $line): $product = $line['product']; ?>
      <div class="cart-line">
        <img src="<?= e(product_image($product)) ?>" alt="" loading="lazy">
        <div class="flex-grow-1 min-w-0">
          <a class="title text-truncate-2" href="/product.php?slug=<?= e($product['slug']) ?>"><?= e($product['title']) ?></a>
          <div class="d-flex align-items-center justify-content-between gap-2">
            <div class="small text-ink-500"><?= (int) $line['quantity'] ?> × <?= e(money($product['price'])) ?></div>
            <div class="small fw-bold"><?= e(money($line['line_total'])) ?></div>
          </div>
          <div class="d-flex justify-content-between align-items-center mt-1">
            <div class="qty-stepper">
              <button type="button" data-qty-step="-1" aria-label="Decrease"><i class="bi bi-dash"></i></button>
              <input type="number" value="<?= (int) $line['quantity'] ?>" min="1" max="<?= (int) $product['stock'] ?>" data-cart-qty="<?= (int) $product['id'] ?>" aria-label="Quantity">
              <button type="button" data-qty-step="1" aria-label="Increase"><i class="bi bi-plus"></i></button>
            </div>
            <button class="btn btn-link btn-sm text-danger p-0 text-decoration-none" type="button" data-cart-remove="<?= (int) $product['id'] ?>">
              <i class="bi bi-trash"></i>
            </button>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="border-top pt-3 mt-2">
    <div class="summary-row"><span>Subtotal</span><span><?= e(money($quote['subtotal'])) ?></span></div>
    <div class="summary-row"><span>Delivery</span><span><?= $quote['shipping'] > 0 ? e(money($quote['shipping'])) : '<span class="text-brand">Calculated at checkout</span>' ?></span></div>
    <?php if ($quote['discount'] > 0): ?><div class="summary-row text-brand"><span>Discount</span><span>−<?= e(money($quote['discount'])) ?></span></div><?php endif; ?>
    <div class="summary-row total"><span>Total</span><span><?= e(money($quote['total'])) ?></span></div>
    <div class="d-grid gap-2 mt-2">
      <a class="btn btn-brand" href="/checkout.php">Checkout <i class="bi bi-arrow-right"></i></a>
      <a class="btn btn-soft" href="/cart.php">View cart</a>
    </div>
    <?php if (Pricing::amountToFreeShipping($quote['subtotal']) > 0): ?>
      <p class="small text-ink-500 mt-2 mb-0 text-center">Add <?= e(money(Pricing::amountToFreeShipping($quote['subtotal']))) ?> more for free delivery</p>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php
$html = (string) ob_get_clean();
json_response(['count' => $cart['count'], 'html' => $html]);

<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!is_post()) {
    redirect('/cart.php');
}
Csrf::verifyRequest();
RateLimiter::guard('api');

$action = (string) post('action', '');
$productId = post_int('product_id');

switch ($action) {
    case 'apply_coupon':
        $code = trim((string) post('code', ''));
        if ($code === '') {
            flash('warning', 'Enter a coupon code first.');
            redirect('/cart.php');
        }
        $cart = Cart::contents();
        if ($cart['items'] === []) {
            flash('warning', 'Add something to your cart before applying a coupon.');
            redirect('/cart.php');
        }
        $check = Coupons::validate($code, [
            'subtotal' => $cart['subtotal'],
            'user_id' => Auth::id(),
            'items' => $cart['items'],
        ]);
        if ($check['valid']) {
            $_SESSION['coupon_code'] = strtoupper($code);
            flash('success', $check['message']);
        } else {
            unset($_SESSION['coupon_code']);
            flash('danger', $check['message']);
        }
        redirect(back_target());

    case 'remove_coupon':
        unset($_SESSION['coupon_code']);
        flash('info', 'Coupon removed.');
        redirect(back_target());

    case 'clear':
        Cart::clear();
        unset($_SESSION['coupon_code']);
        flash('info', 'Your cart is now empty.');
        redirect('/cart.php');

    case 'update':
        $result = Cart::update($productId, post_int('quantity', 1));
        flash($result['ok'] ? 'success' : 'warning', $result['message'] ?? $result['error'] ?? 'Cart updated.');
        redirect(back_target());

    case 'remove':
        Cart::remove($productId);
        flash('info', 'Item removed from your cart.');
        redirect(back_target());

    default:
        flash('danger', 'Unknown cart action.');
        redirect('/cart.php');
}

/** Return to the cart or checkout, never to an external URL. */
function back_target(): string
{
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $path = $ref !== '' && parse_url($ref, PHP_URL_HOST) === $host ? (string) parse_url($ref, PHP_URL_PATH) : '/cart.php';
    return str_starts_with($path, '/checkout') ? '/checkout.php' : '/cart.php';
}

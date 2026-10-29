<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!is_post()) {
    json_response(['total' => count(Cart::wishlistIds()), 'ids' => Cart::wishlistIds()]);
}
Csrf::verifyRequest();
if (!Auth::check()) {
    json_response(['error' => 'Sign in to save favourites.', 'login' => true], 401);
}
$productId = (int) request_input('product_id', '0');
$product = Catalog::product($productId);
if ($product === null || $product['status'] !== 'active') {
    json_response(['error' => 'Product not found.'], 404);
}
$result = Cart::toggleWishlist($productId);
json_response(array_merge($result, ['total' => count(Cart::wishlistIds())]));

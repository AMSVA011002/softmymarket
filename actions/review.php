<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!is_post()) {
    redirect('/');
}
Csrf::verifyRequest();
Auth::requireLogin();
RateLimiter::guard('review');

$productId = post_int('product_id');
$product = Catalog::product($productId);
if ($product === null) {
    abort(404, 'Product not found.');
}
if (!Settings::bool('allow_reviews', true)) {
    flash('warning', 'Reviews are temporarily closed.');
    redirect('/product.php?slug=' . rawurlencode((string) $product['slug']));
}

$rating = max(1, min(5, post_int('rating', 5)));
$title = trim((string) post('title', ''));
$body = trim((string) post('body', ''));

if (strlen($body) < 10) {
    flash('warning', 'Please write at least 10 characters so your review helps other shoppers.');
    redirect('/product.php?slug=' . rawurlencode((string) $product['slug']) . '#tab-reviews');
}

$already = Database::exists(
    'SELECT 1 FROM reviews WHERE product_id = :pid AND user_id = :uid LIMIT 1',
    ['pid' => $productId, 'uid' => Auth::id()]
);

$user = Auth::user();
if ($already) {
    flash('info', 'You have already reviewed this product. Thank you!');
    redirect('/product.php?slug=' . rawurlencode((string) $product['slug']) . '#tab-reviews');
}

Database::insert('reviews', [
    'product_id' => $productId,
    'user_id' => Auth::id(),
    'author_name' => (string) $user['name'],
    'rating' => $rating,
    'title' => $title !== '' ? substr($title, 0, 120) : null,
    'body' => substr($body, 0, 2000),
    'status' => 'pending',
    'helpful' => 0,
    'created_at' => date('Y-m-d H:i:s'),
]);

Notifier::notifyTeam('New review awaiting moderation', $user['name'] . ' reviewed ' . $product['title'], 'info', '/admin/reviews.php');
Audit::log('review.submitted', 'product', $productId, ['rating' => $rating]);
flash('success', 'Thanks for the review! It will appear once our team has checked it.');
redirect('/product.php?slug=' . rawurlencode((string) $product['slug']) . '#tab-reviews');

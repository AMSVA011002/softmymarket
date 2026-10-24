<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

RateLimiter::guard('search');
$term = trim((string) ($_GET['q'] ?? ''));
if (strlen($term) < 2) {
    json_response(['products' => [], 'categories' => []]);
}

$products = Catalog::productSuggestions($term, 6);
$categories = Catalog::categorySuggestions($term, 4);

json_response([
    'query' => $term,
    'products' => array_map(static fn (array $product): array => [
        'id' => (int) $product['id'],
        'title' => $product['title'],
        'price' => money($product['price']),
        'image' => img_url($product['image'] ?: null, (string) $product['title'], 120),
        'url' => '/product.php?slug=' . rawurlencode((string) $product['slug']),
    ], $products),
    'categories' => array_map(static fn (array $category): array => [
        'name' => $category['name'],
        'url' => '/category.php?slug=' . rawurlencode((string) $category['slug']),
    ], $categories),
]);

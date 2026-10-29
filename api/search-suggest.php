<?php
declare(strict_types=1);

/**
 * Live search suggestions.
 *
 * GET /api/search-suggest.php?q=iplo
 *
 * Returns JSON for the search dropdown. The storefront only asks for a term of
 * three characters or more — shorter terms are too noisy to be useful and cost a
 * query on every keystroke.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

RateLimiter::guard('search');

const SUGGEST_MIN_LENGTH = 3;

$term = trim((string) ($_GET['q'] ?? ''));
$term = (string) preg_replace('/\s+/', ' ', $term);

if (mb_strlen($term) < SUGGEST_MIN_LENGTH) {
    json_response([
        'query' => $term,
        'ready' => false,
        'min' => SUGGEST_MIN_LENGTH,
        'products' => [],
        'categories' => [],
        'brands' => [],
        'total' => 0,
        'shop_url' => '/shop.php',
    ]);
}

$products = Catalog::productSuggestions($term, 8);
$categories = Catalog::categorySuggestions($term, 4);
$brands = Catalog::brandSuggestions($term, 3);
$total = Catalog::suggestionCount($term);

json_response([
    'query' => $term,
    'ready' => true,
    'min' => SUGGEST_MIN_LENGTH,
    'total' => $total,
    'shop_url' => '/shop.php?q=' . rawurlencode($term),
    'products' => array_map(static fn (array $product): array => [
        'id' => (int) $product['id'],
        'title' => (string) $product['title'],
        'price' => money($product['price']),
        'was' => !empty($product['compare_at_price']) ? money((float) $product['compare_at_price']) : null,
        'category' => (string) ($product['category_name'] ?? ''),
        'stock' => (int) ($product['stock'] ?? 0),
        'image' => product_image_src($product['image'] ?? null, (string) $product['title'], 120),
        'url' => '/product.php?slug=' . rawurlencode((string) $product['slug']),
    ], $products),
    'categories' => array_map(static fn (array $category): array => [
        'name' => (string) $category['name'],
        'url' => '/category.php?slug=' . rawurlencode((string) $category['slug']),
    ], $categories),
    'brands' => array_map(static fn (array $brand): array => [
        'name' => (string) $brand['name'],
        'url' => '/shop.php?brand[]=' . rawurlencode((string) $brand['slug']),
    ], $brands),
]);

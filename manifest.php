<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$brand = Settings::brand();
$name = trim((string) ($brand['name'] ?? '')) ?: 'YourMarket';
$manifest = [
    'id' => '/',
    'name' => $name,
    'short_name' => mb_substr($name, 0, 12),
    'description' => Settings::get('store_description'),
    'lang' => Settings::get('store_language', 'en-LK'),
    'dir' => 'ltr',
    'categories' => ['shopping'],
    'start_url' => '/?source=manifest',
    'scope' => '/',
    'display' => 'standalone',
    'background_color' => '#ffffff',
    'theme_color' => Settings::get('brand_color', '#0c800f'),
    'icons' => [
        ['src' => '/assets/img/apple-touch-icon.png', 'sizes' => '180x180', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/img/favicon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
    ],
];

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

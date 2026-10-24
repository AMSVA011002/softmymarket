<?php
/**
 * XML sitemap for search engines.
 *
 * Lists the storefront pages, every active category and the newest products.
 * Cached in storage/cache for an hour so crawlers do not hammer the database on
 * shared hosting.
 */
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$cacheFile = STORAGE_PATH . '/cache/sitemap.xml';
$cacheTtl = 3600;
if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
    header('Cache-Control: public, max-age=600');
    readfile($cacheFile);
    exit;
}

$base = rtrim(url('/'), '/');

/** Escape a value for XML. */
$x = static fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

$urls = [];
$add = static function (string $path, string $priority = '0.5', string $freq = 'weekly', ?string $lastmod = null) use (&$urls, $base): void {
    $urls[] = ['loc' => $base . $path, 'priority' => $priority, 'freq' => $freq, 'lastmod' => $lastmod];
};

$add('/', '1.0', 'daily');
$add('/shop.php', '0.9', 'daily');
$add('/deals.php', '0.8', 'daily');
$add('/brands.php', '0.6', 'weekly');
foreach (['about.php', 'contact.php', 'faq.php', 'help.php', 'shipping.php', 'returns.php', 'privacy.php', 'terms.php'] as $page) {
    $add('/' . $page, '0.3', 'monthly');
}

try {
    foreach (Database::all("SELECT slug, updated_at FROM " . Database::table('categories') . " WHERE is_active = 1 ORDER BY depth, position") as $category) {
        $add('/category.php?slug=' . rawurlencode((string) $category['slug']), '0.7', 'weekly', $category['updated_at'] ?? null);
    }
    foreach (Database::all(
        "SELECT slug, updated_at FROM " . Database::table('products') .
        " WHERE status = 'active' AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT 5000"
    ) as $product) {
        $add('/product.php?slug=' . rawurlencode((string) $product['slug']), '0.8', 'weekly', $product['updated_at'] ?? null);
    }
} catch (Throwable $e) {
    error_log('Sitemap query failed: ' . $e->getMessage());
}

$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
    . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $entry) {
    $xml .= '  <url><loc>' . $x($entry['loc']) . '</loc>';
    if (!empty($entry['lastmod'])) {
        $timestamp = strtotime((string) $entry['lastmod']);
        if ($timestamp !== false) {
            $xml .= '<lastmod>' . date('Y-m-d', $timestamp) . '</lastmod>';
        }
    }
    $xml .= '<changefreq>' . $x($entry['freq']) . '</changefreq>'
        . '<priority>' . $x($entry['priority']) . '</priority></url>' . "\n";
}
$xml .= '</urlset>' . "\n";

if (is_dir(dirname($cacheFile)) && is_writable(dirname($cacheFile))) {
    @file_put_contents($cacheFile, $xml);
}

header('Cache-Control: public, max-age=600');
echo $xml;

<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

/**
 * Image endpoint.
 *
 *   /image.php?placeholder=1&label=Product%20name&w=640
 *       Generates a branded SVG placeholder (no external requests, no disk use).
 *
 *   /image.php?src=https://drive.google.com/file/d/FILE_ID/view
 *       Proxies an allow-listed remote image (Google Drive share links are
 *       normalised to a direct download URL) and caches it locally.
 *
 * Security notes
 *   - only hosts from config.images.allowed_hosts are fetched (no SSRF)
 *   - DNS results are checked so a hostname cannot resolve to a private IP
 *   - responses are capped at config.images.max_bytes and must be an image
 *   - redirects are validated hop by hop, never followed blindly
 */

$width = max(80, min(2000, (int) ($_GET['w'] ?? 640)));

/* ------------------------------------------------------------ placeholders */
if (isset($_GET['placeholder'])) {
    $label = trim((string) ($_GET['label'] ?? 'SoftMyMarket'));
    $label = $label === '' ? 'SoftMyMarket' : $label;
    $initials = initials($label, 2);
    $short = function_exists('mb_strtolower') ? mb_strtolower($label) : strtolower($label);
    $hue = (crc32($short) % 60) + 130; // pleasant green/blue band
    $height = (int) round($width * 0.75);

    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: public, max-age=2592000, immutable');
    header('X-Content-Type-Options: nosniff');
    echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="' . e($label) . '">'
        . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
        . '<stop offset="0" stop-color="hsl(' . $hue . ',55%,93%)"/>'
        . '<stop offset="1" stop-color="hsl(' . (($hue + 50) % 360) . ',60%,88%)"/>'
        . '</linearGradient></defs>'
        . '<rect width="100%" height="100%" fill="url(#g)"/>'
        . '<text x="50%" y="48%" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,Roboto,sans-serif" '
        . 'font-size="' . (int) round($width * 0.16) . '" font-weight="800" fill="hsl(' . $hue . ',45%,35%)">' . e($initials) . '</text>'
        . '<text x="50%" y="66%" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,Roboto,sans-serif" '
        . 'font-size="' . (int) round($width * 0.045) . '" fill="hsl(' . $hue . ',30%,42%)">' . e(limit_text($label, 34, '')) . '</text>'
        . '</svg>';
    exit;
}

/* ------------------------------------------------------------------ proxy */
$src = trim((string) ($_GET['src'] ?? ''));
if ($src === '' || !filter_var($src, FILTER_VALIDATE_URL)) {
    abort(400, 'An image URL is required.');
}

$allowedHosts = array_map('strtolower', (array) Config::get('images.allowed_hosts', []));
$maxBytes = (int) Config::get('images.max_bytes', 5242880);
$cacheTtl = (int) Config::get('images.cache_ttl', 604800);
$cacheDir = ROOT_PATH . '/' . ltrim((string) Config::get('images.cache_dir', 'storage/cache/images'), '/');

/** Turn any Google Drive link into a direct download URL. */
function resolve_source(string $url): string
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (in_array($host, ['drive.google.com', 'docs.google.com'], true)) {
        $id = drive_file_id($url);
        if ($id !== null) {
            return 'https://drive.google.com/uc?export=download&id=' . rawurlencode($id);
        }
    }
    if ($host === 'lh3.googleusercontent.com') {
        return $url;
    }
    return $url;
}

/** Reject hosts that are not allow-listed or resolve to private ranges. */
function assert_allowed(string $url, array $allowedHosts): void
{
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        throw new RuntimeException('Unsupported image URL.');
    }
    $allowed = false;
    foreach ($allowedHosts as $candidate) {
        if ($host === $candidate || str_ends_with($host, '.' . $candidate)) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        throw new RuntimeException('That image host is not allowed.');
    }

    $ips = gethostbynamel($host);
    if ($ips !== false) {
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('That image host is not reachable.');
            }
        }
    }
}

$source = resolve_source($src);
try {
    assert_allowed($source, $allowedHosts);
} catch (Throwable $e) {
    abort(400, $e->getMessage());
}

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}
$cacheKey = hash('sha256', $source);
$cacheFile = $cacheDir . '/' . $cacheKey . '.bin';
$metaFile = $cacheDir . '/' . $cacheKey . '.json';

$serve = static function (string $path, string $mime) use ($cacheKey): void {
    $etag = '"' . $cacheKey . '-' . (string) filemtime($path) . '"';
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: public, max-age=604800, stale-while-revalidate=86400');
    header('ETag: ' . $etag);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline');
    readfile($path);
    exit;
};

if (is_file($cacheFile) && is_file($metaFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
    $meta = json_decode((string) file_get_contents($metaFile), true);
    $serve($cacheFile, is_array($meta) ? (string) ($meta['mime'] ?? 'image/jpeg') : 'image/jpeg');
}

$allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/avif' => 'avif'];

/** Fetch with a hard size cap and redirect validation. */
function fetch_image(string $url, array $allowedHosts, int $maxBytes): array
{
    $attempts = 0;
    while ($attempts < 4) {
        $attempts++;
        if (function_exists('curl_init')) {
            $buffer = '';
            $headers = [];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_USERAGENT => 'SoftMyMarket/1.0 (+image proxy)',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                    $trimmed = trim($line);
                    if (stripos($trimmed, 'content-type:') === 0) {
                        $headers['content-type'] = trim(substr($trimmed, 13));
                    }
                    if (stripos($trimmed, 'location:') === 0) {
                        $headers['location'] = trim(substr($trimmed, 9));
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$buffer, $maxBytes): int {
                    if (strlen($buffer) + strlen($chunk) > $maxBytes) {
                        return 0; // abort
                    }
                    $buffer .= $chunk;
                    return strlen($chunk);
                },
            ]);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if (in_array($status, [301, 302, 303, 307, 308], true) && !empty($headers['location'])) {
                $url = make_absolute((string) $headers['location'], $url);
                assert_allowed($url, $allowedHosts);
                continue;
            }
            if ($status !== 200) {
                throw new RuntimeException('The image host returned an error (' . $status . ').');
            }
            return [$buffer, (string) ($headers['content-type'] ?? '')];
        }

        // Fallback: streams (no curl extension).
        $context = stream_context_create([
            'http' => ['timeout' => 15, 'follow_location' => 0, 'user_agent' => 'SoftMyMarket/1.0'],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $data = @file_get_contents($url, false, $context, 0, $maxBytes);
        if ($data === false) {
            throw new RuntimeException('The image could not be downloaded.');
        }
        $mime = '';
        foreach ($http_response_header ?? [] as $header) {
            if (stripos($header, 'content-type:') === 0) {
                $mime = trim(substr($header, 13));
            }
        }
        return [$data, $mime];
    }
    throw new RuntimeException('Too many redirects.');
}

function make_absolute(string $location, string $base): string
{
    if (preg_match('#^https?://#i', $location)) {
        return $location;
    }
    $parts = parse_url($base);
    $scheme = $parts['scheme'] ?? 'https';
    $host = $parts['host'] ?? '';
    if (str_starts_with($location, '//')) {
        return $scheme . ':' . $location;
    }
    if (str_starts_with($location, '/')) {
        return $scheme . '://' . $host . $location;
    }
    $dir = rtrim(dirname($parts['path'] ?? '/'), '/');
    return $scheme . '://' . $host . $dir . '/' . $location;
}

try {
    [$bytes, $mime] = fetch_image($source, $allowedHosts, $maxBytes);
} catch (Throwable $e) {
    error_log('Image proxy failed for ' . $source . ': ' . $e->getMessage());
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 300"><rect width="400" height="300" fill="#f1f5f9"/>'
        . '<text x="50%" y="50%" text-anchor="middle" font-family="sans-serif" font-size="16" fill="#94a3b8">Image unavailable</text></svg>';
    exit;
}

$mime = strtolower(trim(explode(';', $mime)[0]));
if (!isset($allowedMimes[$mime])) {
    // Some hosts send application/octet-stream for images; sniff the magic bytes.
    $info = @getimagesizefromstring($bytes);
    $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
    if (!isset($allowedMimes[$mime])) {
        abort(415, 'That URL did not return an image.');
    }
}

file_put_contents($cacheFile, $bytes);
file_put_contents($metaFile, json_encode(['mime' => $mime, 'source' => $source, 'at' => time()], JSON_UNESCAPED_SLASHES));
$serve($cacheFile, $mime);

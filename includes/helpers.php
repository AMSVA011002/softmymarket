<?php
declare(strict_types=1);

/* ---------------------------------------------------------------------------
 * mbstring fallbacks.
 *
 * Some shared-hosting stacks ship without the mbstring extension. The store
 * only needs a handful of multibyte helpers, so we provide UTF-8 safe
 * equivalents rather than failing at runtime.
 * ------------------------------------------------------------------------ */
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $text, ?string $encoding = null): int
    {
        $count = preg_match_all('/./us', $text);
        return $count === false ? strlen($text) : $count;
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $text, int $start, ?int $length = null, ?string $encoding = null): string
    {
        preg_match_all('/./us', $text, $matches);
        $chars = $matches[0] ?? [];
        $slice = $length === null ? array_slice($chars, $start) : array_slice($chars, $start, $length);
        return implode('', $slice);
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $text, ?string $encoding = null): string
    {
        return strtolower($text);
    }
}
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $text, ?string $encoding = null): string
    {
        return strtoupper($text);
    }
}
if (!function_exists('mb_str_pad')) {
    function mb_str_pad(string $text, int $length, string $pad = ' ', int $type = STR_PAD_RIGHT): string
    {
        return str_pad($text, $length, $pad, $type);
    }
}

/**
 * Global helpers used by templates, actions and API endpoints.
 *
 * Nothing in this file touches the database directly — data access lives in the
 * repository classes so it stays testable and easy to cache.
 */

/* ------------------------------------------------------------------ output */

/** HTML-escape a value for text and attribute contexts. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Escape and convert newlines to <br>. */
function e_nl2br(mixed $value): string
{
    return nl2br(e($value), false);
}

/** JSON-encode data for an inline <script type="application/json"> block. */
function json_attr(mixed $data): string
{
    return e(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: 'null');
}

/** Trim and collapse whitespace. */
function squish(?string $value): string
{
    return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
}

/* -------------------------------------------------------------------- urls */

/** Absolute URL for an in-app path. */
function url(string $path = '', array $query = []): string
{
    $base = rtrim((string) Config::get('app.url', ''), '/');
    if ($base === '') {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $base = Security::scheme() . '://' . $host;
    }
    $path = '/' . ltrim($path, '/');
    if ($path === '/') {
        $path = '/';
    }
    $url = $base . $path;
    $query = array_filter($query, static fn ($v) => $v !== null && $v !== '');
    if ($query !== []) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }
    return $url;
}

/** Root-relative URL (safe for redirects and HTML output). */
function path_url(string $path = '', array $query = []): string
{
    $path = '/' . ltrim($path, '/');
    $query = array_filter($query, static fn ($v) => $v !== null && $v !== '');
    if ($query === []) {
        return $path;
    }
    return $path . (str_contains($path, '?') ? '&' : '?') . http_build_query($query);
}

/** Static asset URL with a cache-busting fingerprint. */
function asset(string $path): string
{
    static $mtime = [];
    $relative = ltrim($path, '/');
    if (!isset($mtime[$relative])) {
        $file = ROOT_PATH . '/' . $relative;
        $mtime[$relative] = is_file($file) ? (string) filemtime($file) : SMM_VERSION;
    }
    return '/' . $relative . '?v=' . $mtime[$relative];
}

/** Current request path without query string. */
function current_path(): string
{
    return parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
}

/** True when the current path starts with the given prefix. */
function nav_active(string $prefix, bool $exact = false): bool
{
    $current = rtrim(current_path(), '/');
    $prefix = rtrim($prefix, '/');
    if ($current === '') {
        $current = '/';
    }
    return $exact ? $current === $prefix : ($current === $prefix || str_starts_with($current, $prefix . '/'));
}

/** Redirect and stop. Only same-site paths are accepted. */
function redirect(string $path, int $status = 302): never
{
    if (preg_match('#^(https?:)?//#i', $path) || str_starts_with($path, '\\')) {
        $path = '/';
    }
    if (!headers_sent()) {
        header('Location: ' . $path, true, $status);
    } else {
        echo '<script nonce="' . Security::nonce() . '">location.replace(' . json_encode($path) . ')</script>';
    }
    exit;
}

/** Redirect back to the referrer when it is a local page. */
function back(string $fallback = '/'): never
{
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($referer !== '' && parse_url($referer, PHP_URL_HOST) === $host) {
        $path = (string) parse_url($referer, PHP_URL_PATH);
        $query = parse_url($referer, PHP_URL_QUERY);
        redirect($path . ($query ? '?' . $query : ''));
    }
    redirect($fallback);
}

/** Rebuild the current URL with query overrides. */
function with_query(array $overrides, ?string $path = null): string
{
    $query = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    return path_url($path ?? current_path(), $query);
}

/* ------------------------------------------------------------------ flash */

/** Queue a one-shot flash message (info|success|warning|danger). */
function flash(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/** @return list<array{type:string,message:string}> */
function flashes(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return [];
    }
    $items = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($items) ? $items : [];
}

/* ------------------------------------------------------------------ input */

/** Read a request value (POST first, then GET) as a trimmed string. */
function request_input(string $key, ?string $default = null): ?string
{
    $value = $_POST[$key] ?? $_GET[$key] ?? null;
    if (is_array($value)) {
        return $default;
    }
    if ($value === null) {
        return $default;
    }
    return trim((string) $value);
}

function query(string $key, ?string $default = null): ?string
{
    $value = $_GET[$key] ?? null;
    if (is_array($value) || $value === null) {
        return $default;
    }
    $value = trim((string) $value);
    return $value === '' ? $default : $value;
}

function post(string $key, ?string $default = null): ?string
{
    $value = $_POST[$key] ?? null;
    if (is_array($value) || $value === null) {
        return $default;
    }
    return trim((string) $value);
}

function query_int(string $key, int $default = 0, int $min = 0, int $max = PHP_INT_MAX): int
{
    $value = filter_var($_GET[$key] ?? null, FILTER_VALIDATE_INT);
    if ($value === false || $value === null) {
        return $default;
    }
    return max($min, min($max, $value));
}

function post_int(string $key, int $default = 0): int
{
    $value = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT);
    return $value === false ? $default : $value;
}

function query_bool(string $key): bool
{
    $value = $_GET[$key] ?? null;
    return $value !== null && in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
}

/** Persist submitted values for the next request (form redisplay). */
function remember_input(array $values): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['_old'] = $values;
    }
}

function old(string $key, mixed $default = ''): mixed
{
    static $old = null;
    if ($old === null) {
        $old = $_SESSION['_old'] ?? [];
        unset($_SESSION['_old']);
    }
    return $old[$key] ?? $default;
}

/**
 * Endpoints that receive POSTs from third parties and therefore cannot carry a
 * session CSRF token. They authenticate their callers another way (HMAC
 * signature for gateway callbacks, or the installer's own token check).
 *
 * @return list<string>
 */
function csrf_exempt_paths(): array
{
    return [
        '/payment/notify.php',
        '/payment/return.php',
        '/install/index.php',
    ];
}

/**
 * Global CSRF guard, called from includes/bootstrap.php for every POST.
 *
 * Individual handlers still call Csrf::verifyRequest() (defence in depth), but
 * this makes it impossible to forget: a new endpoint is protected by default
 * and must opt out by adding its path to csrf_exempt_paths().
 */
function enforce_csrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || IS_CLI) {
        return;
    }
    $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    $path = '/' . ltrim($path, '/');
    foreach (csrf_exempt_paths() as $exempt) {
        if ($path === $exempt) {
            return;
        }
    }
    Csrf::verifyRequest();
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function is_ajax(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function wants_json(): bool
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    return is_ajax() || str_contains($accept, 'application/json') || isset($_GET['json']);
}

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function abort(int $status, string $message = ''): never
{
    http_response_code($status);
    if (wants_json()) {
        json_response(['error' => $message !== '' ? $message : 'Request failed'], $status);
    }
    $title = match ($status) {
        403 => 'Access denied',
        404 => 'Page not found',
        419 => 'Session expired',
        429 => 'Too many requests',
        default => 'Something went wrong',
    };
    $app = e((string) Config::get('app.name', 'SoftMyMarket'));
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . $status . ' — ' . $title . '</title><link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css"></head>';
    echo '<body class="bg-light d-flex align-items-center min-vh-100"><div class="container" style="max-width:560px">';
    echo '<div class="card shadow-sm"><div class="card-body p-4 text-center">';
    echo '<p class="display-6 mb-1">' . $status . '</p><h1 class="h4 fw-bold">' . e($title) . '</h1>';
    echo '<p class="text-secondary">' . e($message) . '</p>';
    echo '<a class="btn btn-success mt-2" href="/">Back to ' . $app . '</a>';
    echo '</div></div></div></body></html>';
    exit;
}

/* ----------------------------------------------------------------- format */

/** Format a money amount using the configured currency symbol. */
function money(float|int|string|null $amount, bool $withSymbol = true): string
{
    $value = (float) ($amount ?? 0);
    $formatted = number_format($value, 2, '.', ',');
    // Drop trailing ".00" so the storefront reads naturally.
    if (str_ends_with($formatted, '.00')) {
        $formatted = substr($formatted, 0, -3);
    }
    if (!$withSymbol) {
        return $formatted;
    }
    return (string) Config::get('app.currency_symbol', 'Rs') . ' ' . $formatted;
}

/** Compact number for dashboards: 12.4k, 3.1M. */
function compact_number(float|int $value): string
{
    $value = (float) $value;
    if ($value >= 1_000_000) {
        return rtrim(rtrim(number_format($value / 1_000_000, 1), '0'), '.') . 'M';
    }
    if ($value >= 1_000) {
        return rtrim(rtrim(number_format($value / 1_000, 1), '0'), '.') . 'k';
    }
    return (string) (int) $value;
}

function fmt_date(?string $date, string $format = 'd M Y'): string
{
    if ($date === null || $date === '' || str_starts_with($date, '0000')) {
        return '—';
    }
    $ts = strtotime($date);
    return $ts === false ? '—' : date($format, $ts);
}

function fmt_datetime(?string $date): string
{
    return fmt_date($date, 'd M Y, g:i a');
}

function time_ago(?string $date): string
{
    if ($date === null || $date === '') {
        return '—';
    }
    $ts = strtotime($date);
    if ($ts === false) {
        return '—';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'just now';
    }
    $units = [31536000 => 'year', 2592000 => 'month', 604800 => 'week', 86400 => 'day', 3600 => 'hour', 60 => 'minute'];
    foreach ($units as $seconds => $label) {
        if ($diff >= $seconds) {
            $count = (int) floor($diff / $seconds);
            return $count . ' ' . $label . ($count > 1 ? 's' : '') . ' ago';
        }
    }
    return 'just now';
}

function initials(?string $name, int $length = 2): string
{
    $name = squish($name);
    if ($name === '') {
        return '?';
    }
    $parts = preg_split('/[\s\-_]+/', $name) ?: [];
    $letters = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $letters .= strtoupper(substr($part, 0, 1));
        if (strlen($letters) >= $length) {
            break;
        }
    }
    return $letters !== '' ? $letters : strtoupper(substr($name, 0, $length));
}

function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    $text = trim($text, '-');
    return $text !== '' ? $text : 'item-' . substr(bin2hex(random_bytes(3)), 0, 5);
}

function limit_text(?string $text, int $limit = 120, string $suffix = '…'): string
{
    $text = squish($text);
    if (function_exists('mb_strlen')) {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $limit - 1)) . $suffix;
    }
    if (strlen($text) <= $limit) {
        return $text;
    }
    return rtrim(substr($text, 0, $limit - 1)) . $suffix;
}

/** Normalise a Sri Lankan phone number to +94 format for storage. */
function normalize_phone(?string $phone): string
{
    $digits = preg_replace('/[^0-9+]/', '', (string) $phone) ?? '';
    if (str_starts_with($digits, '+94')) {
        $digits = '0' . substr($digits, 3);
    } elseif (str_starts_with($digits, '94') && strlen($digits) === 11) {
        $digits = '0' . substr($digits, 2);
    } elseif (str_starts_with($digits, '0')) {
        // already local
    } elseif (strlen($digits) === 9) {
        $digits = '0' . $digits;
    }
    return $digits;
}

function is_valid_phone(?string $phone): bool
{
    $normalized = normalize_phone($phone);
    return (bool) preg_match('/^0[1-9][0-9]{8}$/', $normalized);
}

function phone_display(?string $phone): string
{
    $normalized = normalize_phone($phone);
    if (strlen($normalized) === 10) {
        return substr($normalized, 0, 3) . ' ' . substr($normalized, 3, 3) . ' ' . substr($normalized, 6);
    }
    return (string) $phone;
}

/** Deterministic UUID v4-shaped id for public-facing records. */
function uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/** Human-friendly order number: SMM-2026-000123. */
function order_number(int $id): string
{
    return 'SMM-' . date('Y') . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

/* ----------------------------------------------------------------- images */

/** Extract a Google Drive file id from the usual share/open/uc link shapes. */
function drive_file_id(string $url): ?string
{
    $patterns = [
        '#drive\.google\.com/file/d/([a-zA-Z0-9_-]{10,})#',
        '#drive\.google\.com/open\?id=([a-zA-Z0-9_-]{10,})#',
        '#drive\.google\.com/uc\?(?:export=\w+&)?id=([a-zA-Z0-9_-]{10,})#',
        '#docs\.google\.com/\w+/d/([a-zA-Z0-9_-]{10,})#',
        '#^([a-zA-Z0-9_-]{25,})$#',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, trim($url), $matches)) {
            return $matches[1];
        }
    }
    return null;
}

function is_remote_url(?string $url): bool
{
    return is_string($url) && (bool) preg_match('#^https?://#i', trim($url));
}

/**
 * Turn any stored image reference into a browser-safe src.
 *
 * Accepts:
 *   - Google Drive share links (any format) — served through the image proxy
 *   - absolute https URLs                     — served as-is
 *   - local paths (/assets/...)               — served as-is
 *   - anything else                           — generated placeholder
 */
function img_url(?string $image, ?string $fallback = null, int $width = 640): string
{
    $image = trim((string) $image);
    if ($image === '') {
        return image_placeholder($fallback ?? 'SoftMyMarket', $width);
    }
    if (preg_match('#^https?://(www\.)?(drive|docs)\.google\.com/#i', $image) || preg_match('#^https?://lh3\.googleusercontent\.com/#i', $image)) {
        return '/image.php?src=' . rawurlencode($image);
    }
    if (is_remote_url($image)) {
        return $image;
    }
    if (str_starts_with($image, '/')) {
        return $image;
    }
    return '/' . ltrim($image, '/');
}

/** Inline SVG placeholder with the product initials — works offline. */
function image_placeholder(string $label, int $width = 640): string
{
    $text = rawurlencode(limit_text($label, 22, ''));
    return '/image.php?placeholder=1&label=' . $text . '&w=' . $width;
}

/** First image of a product record. */
function product_image(array $product): string
{
    $images = $product['images'] ?? [];
    if (is_string($images)) {
        $decoded = json_decode($images, true);
        $images = is_array($decoded) ? $decoded : [];
    }
    $first = is_array($images) && $images !== [] ? (string) reset($images) : '';
    return img_url($first, $product['title'] ?? 'Product');
}

/* -------------------------------------------------------------- taxonomy */

/**
 * Build a nested tree from a flat category list.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function build_tree(array $rows, int|string|null $parentId = null): array
{
    $tree = [];
    foreach ($rows as $row) {
        $rowParent = $row['parent_id'] ?? null;
        $matches = ($parentId === null && $rowParent === null)
            || ($parentId !== null && $rowParent !== null && (string) $rowParent === (string) $parentId);
        if ($matches) {
            $children = build_tree($rows, $row['id']);
            if ($children !== []) {
                $row['children'] = $children;
            }
            $tree[] = $row;
        }
    }
    return $tree;
}

/** Breadcrumb trail (ancestors first) for a category id, using path "1/4/9". */
function category_breadcrumb(array $byId, ?string $path): array
{
    if ($path === null || $path === '') {
        return [];
    }
    $trail = [];
    foreach (explode('/', $path) as $id) {
        if (isset($byId[$id])) {
            $trail[] = $byId[$id];
        }
    }
    return $trail;
}

/* ------------------------------------------------------------- pagination */

/**
 * @return array{page:int,pages:int,total:int,limit:int,offset:int,from:int,to:int,hasPrev:bool,hasNext:bool}
 */
function paginate(int $total, int $page, int $limit): array
{
    $limit = max(1, $limit);
    $pages = max(1, (int) ceil($total / $limit));
    $page = max(1, min($pages, $page));
    $offset = ($page - 1) * $limit;
    return [
        'page'    => $page,
        'pages'   => $pages,
        'total'   => $total,
        'limit'   => $limit,
        'offset'  => $offset,
        'from'    => $total === 0 ? 0 : $offset + 1,
        'to'      => min($total, $offset + $limit),
        'hasPrev' => $page > 1,
        'hasNext' => $page < $pages,
    ];
}

/** Render Bootstrap pagination markup (returns HTML). */
function pagination_links(array $pager, array $query = []): string
{
    if (($pager['pages'] ?? 1) <= 1) {
        return '';
    }
    $page = (int) $pager['page'];
    $pages = (int) $pager['pages'];
    $window = 2;
    $start = max(1, $page - $window);
    $end = min($pages, $page + $window);

    $html = '<nav aria-label="Pagination"><ul class="pagination pagination-sm flex-wrap justify-content-center mb-0">';
    $link = static function (int $target, string $label, bool $active = false, bool $disabled = false, string $extra = '') use ($query): string {
        if ($disabled) {
            return '<li class="page-item disabled"><span class="page-link">' . $label . '</span></li>';
        }
        $href = with_query(array_merge($query, ['page' => $target]));
        $classes = 'page-link' . ($active ? ' active' : '');
        return '<li class="page-item' . ($active ? ' active' : '') . '"><a class="' . $classes . '" href="' . e($href) . '" ' . $extra . '>' . $label . '</a></li>';
    };

    $html .= $link($page - 1, '&laquo;', false, !$pager['hasPrev'], 'aria-label="Previous page"');
    if ($start > 1) {
        $html .= $link(1, '1');
        if ($start > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
    }
    for ($i = $start; $i <= $end; $i++) {
        $html .= $link($i, (string) $i, $i === $page, false, $i === $page ? 'aria-current="page"' : '');
    }
    if ($end < $pages) {
        if ($end < $pages - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
        $html .= $link($pages, (string) $pages);
    }
    $html .= $link($page + 1, '&raquo;', false, !$pager['hasNext'], 'aria-label="Next page"');
    $html .= '</ul></nav>';
    return $html;
}

/* ----------------------------------------------------------- status labels */

function order_statuses(): array
{
    return [
        'PENDING'          => 'Pending',
        'CONFIRMED'        => 'Confirmed',
        'PROCESSING'       => 'Processing',
        'PACKED'           => 'Packed',
        'SHIPPED'          => 'Shipped',
        'OUT_FOR_DELIVERY' => 'Out for delivery',
        'DELIVERED'        => 'Delivered',
        'CANCELLED'        => 'Cancelled',
        'RETURNED'         => 'Returned',
        'REFUNDED'         => 'Refunded',
    ];
}

function order_status_label(?string $status): string
{
    return order_statuses()[$status ?? ''] ?? ucfirst(strtolower((string) $status));
}

function order_status_color(?string $status): string
{
    return match ($status) {
        'PENDING'   => 'warning',
        'CONFIRMED' => 'info',
        'PROCESSING' => 'info',
        'PACKED'    => 'primary',
        'SHIPPED'   => 'primary',
        'OUT_FOR_DELIVERY' => 'primary',
        'DELIVERED' => 'success',
        'CANCELLED' => 'secondary',
        'RETURNED'  => 'danger',
        'REFUNDED'  => 'dark',
        default     => 'secondary',
    };
}

function order_status_badge(?string $status, string $extra = ''): string
{
    return '<span class="badge text-bg-' . order_status_color($status) . ' status-badge ' . e($extra) . '">'
        . e(order_status_label($status)) . '</span>';
}

function payment_statuses(): array
{
    return [
        'UNPAID'     => 'Unpaid',
        'PENDING'    => 'Awaiting verification',
        'PAID'       => 'Paid',
        'PARTIAL'    => 'Partially paid',
        'FAILED'     => 'Failed',
        'REFUNDED'   => 'Refunded',
        'CANCELLED'  => 'Cancelled',
    ];
}

function payment_status_label(?string $status): string
{
    return payment_statuses()[$status ?? ''] ?? ucfirst(strtolower((string) $status));
}

function payment_status_color(?string $status): string
{
    return match ($status) {
        'PAID'    => 'success',
        'PENDING' => 'warning',
        'FAILED'  => 'danger',
        'REFUNDED' => 'dark',
        'PARTIAL' => 'info',
        default   => 'secondary',
    };
}

function payment_status_badge(?string $status): string
{
    return '<span class="badge text-bg-' . payment_status_color($status) . ' status-badge">' . e(payment_status_label($status)) . '</span>';
}

function payment_methods(): array
{
    return [
        'COD'           => 'Cash on delivery',
        'CARD_SEYLAN'   => 'Card — Seylan Bank',
        'BANK_TRANSFER' => 'Bank transfer',
        'KOKO'          => 'KOKO — pay in 3',
        'PAYZY'         => 'Payzy wallet',
        'MINTPAY'       => 'Mintpay — pay later',
    ];
}

function payment_method_label(?string $method): string
{
    return payment_methods()[$method ?? ''] ?? ucfirst(strtolower((string) $method));
}

function payment_method_icon(?string $method): string
{
    return match ($method) {
        'COD'           => 'bi-cash-coin',
        'CARD_SEYLAN'   => 'bi-credit-card-2-front',
        'BANK_TRANSFER' => 'bi-bank',
        'KOKO'          => 'bi-phone',
        'PAYZY'         => 'bi-wallet2',
        'MINTPAY'       => 'bi-calendar-check',
        default         => 'bi-credit-card',
    };
}

function order_statuses_flow(): array
{
    return ['PENDING', 'CONFIRMED', 'PROCESSING', 'PACKED', 'SHIPPED', 'OUT_FOR_DELIVERY', 'DELIVERED'];
}

function role_label(?string $role): string
{
    return match ($role) {
        'admin'  => 'Administrator',
        'staff'  => 'Store user',
        'customer' => 'Customer',
        default  => ucfirst((string) $role),
    };
}

/** Discount percentage (0 when there is no compare-at price). */
function discount_percent(float|int|string|null $price, float|int|string|null $compareAt): int
{
    $price = (float) $price;
    $compareAt = (float) $compareAt;
    if ($compareAt <= 0 || $price <= 0 || $compareAt <= $price) {
        return 0;
    }
    return (int) round((($compareAt - $price) / $compareAt) * 100);
}

/** Format a spec value coming from JSON. */
function spec_value(mixed $value): string
{
    if (is_array($value)) {
        return implode(', ', array_map(static fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $value));
    }
    return (string) $value;
}

/** CSV-safe escaping for exports. */
function csv_line(array $values): string
{
    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
        return '';
    }
    fputcsv($handle, $values, ',', '"', '\\');
    rewind($handle);
    $line = (string) stream_get_contents($handle);
    fclose($handle);
    return $line;
}

/** Small deterministic colour from a string (avatar backgrounds). */
function color_from_string(string $value): string
{
    $palette = ['0c800f', '0a57a0', 'b45309', '7c3aed', 'be123c', '0f766e', '4338ca', 'a16207'];
    $hash = crc32($value);
    return '#' . $palette[$hash % count($palette)];
}

/** Human label for a settings field key: free_shipping_threshold → Free shipping threshold. */
function humanize(string $key): string
{
    return ucfirst(str_replace('_', ' ', $key));
}

/** True when the visitor is on a small screen (client hint only, used for UX hints). */
function is_mobile_hint(): bool
{
    return (bool) preg_match('/Mobile|Android|iPhone|iPad|iPod/i', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

/**
 * Serves a bank-transfer slip from storage (outside the web root) to the
 * customer who uploaded it or to a member of staff.
 */
$file = basename((string) query('file', ''));
if ($file === '' || !preg_match('/^[A-Za-z0-9._-]{6,190}$/', $file) || str_contains($file, '..')) {
    abort(404, 'File not found.');
}
$path = STORAGE_PATH . '/uploads/proofs/' . $file;
if (!is_file($path)) {
    abort(404, 'File not found.');
}

if (!Auth::check()) {
    redirect('/login.php?next=' . rawurlencode(current_path() . '?file=' . rawurlencode($file)));
}

$url = '/payment/proof.php?file=' . rawurlencode($file);
$authorised = Auth::isTeam();
if (!$authorised) {
    $order = Database::one(
        'SELECT id FROM orders WHERE receipt_url = :url AND user_id = :uid LIMIT 1',
        ['url' => $url, 'uid' => Auth::id()]
    );
    $payment = $order === null ? Database::one(
        'SELECT pay.id FROM payments pay JOIN orders o ON o.id = pay.order_id
          WHERE pay.receipt_url = :url2 AND o.user_id = :uid2 LIMIT 1',
        ['url2' => $url, 'uid2' => Auth::id()]
    ) : null;
    $authorised = $order !== null || $payment !== null;
}
if (!$authorised) {
    abort(403, 'You do not have access to this file.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $finfo->file($path);
$allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
if (!in_array($mime, $allowed, true)) {
    abort(415, 'Unsupported file type.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="' . $file . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($path);

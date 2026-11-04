<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
Auth::requireLogin();

$attachment = SellerMessages::attachment(query_int('id'));
if ($attachment === null) {
    abort(404, 'Image not found.');
}
$allowed = Auth::can('messages.view')
    || (Auth::isCustomer() && (int) $attachment['user_id'] === (int) Auth::id());
if (!$allowed) {
    abort(404, 'Image not found.');
}

$storedName = (string) $attachment['storage_name'];
if (!preg_match('/^\d{8}-[a-f0-9]{16}\.(?:jpg|png|webp)$/D', $storedName)) {
    abort(404, 'Image not found.');
}
$directory = realpath(STORAGE_PATH . '/uploads/seller-messages');
$file = realpath(STORAGE_PATH . '/uploads/seller-messages/' . $storedName);
if ($directory === false || $file === false || !str_starts_with($file, $directory . DIRECTORY_SEPARATOR) || !is_file($file)) {
    abort(404, 'Image not found.');
}
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $finfo->file($file);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $mime !== $attachment['mime_type']) {
    abort(404, 'Image not found.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($file));
header('Content-Disposition: inline; filename="attachment.' . match ($mime) {
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    default => 'webp',
} . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($file);
exit;

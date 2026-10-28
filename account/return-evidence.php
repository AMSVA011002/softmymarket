<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
Auth::requireLogin();
$request = ReturnService::find(query_int('id'));
if ($request === null) {
    abort(404, 'Evidence not found.');
}
$allowed = ReturnService::ownedBy($request, (int) Auth::id()) || (Auth::isTeam() && Auth::can('orders.view'));
if (!$allowed || empty($request['evidence_file'])) {
    abort(404, 'Evidence not found.');
}
$file = (string) $request['evidence_file'];
if (!preg_match('/^\d{8}-[a-f0-9]{16}\.(jpg|png|webp|gif|pdf)$/', $file)) {
    abort(404, 'Evidence not found.');
}
$path = STORAGE_PATH . '/uploads/returns/' . $file;
if (!is_file($path)) {
    abort(404, 'Evidence file is unavailable.');
}
header('Content-Type: ' . (string) ($request['evidence_mime'] ?: 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: attachment; filename="return-evidence-' . (int) $request['id'] . '.' . pathinfo($file, PATHINFO_EXTENSION) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;

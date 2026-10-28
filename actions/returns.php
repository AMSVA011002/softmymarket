<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (!is_post()) {
    redirect('/account/returns.php');
}
Csrf::verifyRequest();
Auth::requireLogin();
RateLimiter::guard('api');
$userId = (int) Auth::id();
$action = (string) post('action', '');

switch ($action) {
    case 'create':
        $orderId = post_int('order_id');
        $evidenceFile = null;
        $evidenceMime = null;
        $storedPath = null;
        if (isset($_FILES['evidence']) && (int) ($_FILES['evidence']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $allowed = Uploads::IMAGE_MIME + Uploads::DOCUMENT_MIME;
            $upload = Uploads::store($_FILES['evidence'], 'returns', $allowed, 5242880);
            if (!$upload['ok']) {
                flash('danger', (string) ($upload['error'] ?? 'Evidence upload failed.'));
                redirect('/account/returns.php?order_id=' . $orderId);
            }
            $evidenceFile = (string) $upload['file'];
            $evidenceMime = (string) $upload['mime'];
            $storedPath = (string) $upload['path'];
        }
        $quantities = [];
        foreach ((array) ($_POST['quantity'] ?? []) as $itemId => $quantity) {
            if (is_scalar($itemId) && is_scalar($quantity)) {
                $quantities[(int) $itemId] = (int) $quantity;
            }
        }
        $result = ReturnService::create(
            $userId,
            $orderId,
            $quantities,
            strtoupper((string) post('reason', '')),
            (string) post('details', ''),
            $evidenceFile,
            $evidenceMime
        );
        if (!$result['ok'] && $storedPath !== null) {
            Uploads::delete($storedPath);
        }
        flash($result['ok'] ? 'success' : 'danger', $result['ok'] ? 'Return request ' . $result['number'] . ' submitted.' : (string) $result['error']);
        redirect($result['ok'] ? '/account/return.php?id=' . (int) $result['id'] : '/account/returns.php?order_id=' . $orderId);

    case 'tracking':
        $id = post_int('return_id');
        $result = ReturnService::submitTracking($id, $userId, (string) post('carrier', ''), (string) post('tracking', ''));
        flash($result['ok'] ? 'success' : 'danger', $result['message']);
        redirect('/account/return.php?id=' . $id);

    case 'cancel':
        $id = post_int('return_id');
        $request = ReturnService::find($id);
        if ($request === null || !ReturnService::ownedBy($request, $userId)) {
            abort(404, 'Return request not found.');
        }
        $result = ReturnService::transition($id, 'CANCELLED', 'Cancelled by the customer.', 'customer', $userId);
        flash($result['ok'] ? 'success' : 'danger', $result['message']);
        redirect('/account/return.php?id=' . $id);

    default:
        flash('danger', 'Unknown return request.');
        redirect('/account/returns.php');
}

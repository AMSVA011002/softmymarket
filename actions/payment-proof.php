<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!is_post()) {
    redirect('/');
}
Csrf::verifyRequest();
RateLimiter::guard('upload');

$uuid = (string) post('order_uuid', '');
$token = (string) post('token', '');
$order = $uuid !== '' ? Orders::findByUuid($uuid) : Orders::find(post_int('order_id'));
if ($order === null) {
    abort(404, 'Order not found.');
}

$payload = $token !== '' ? Security::verify($token) : null;
$authorised = (Auth::check() && Orders::ownedBy($order, Auth::id()))
    || Auth::isTeam()
    || ($payload !== null && (int) ($payload['order'] ?? 0) === (int) $order['id']);
if (!$authorised) {
    abort(403, 'This order link has expired. Please sign in to your account.');
}

$back = '/order-success.php?order=' . rawurlencode((string) $order['uuid']) . '&token=' . rawurlencode($token);
if ($order['payment_status'] === 'PAID') {
    flash('info', 'This order is already paid.');
    redirect($back);
}

$reference = trim((string) post('reference', ''));
$allowed = Uploads::IMAGE_MIME + Uploads::DOCUMENT_MIME;
$stored = null;

if (!empty($_FILES['slip']['name'])) {
    $result = Uploads::store($_FILES['slip'], 'proofs', $allowed, (int) Config::get('uploads.max_bytes', 5242880), false);
    if (!$result['ok']) {
        flash('danger', $result['error'] ?? 'We could not accept that file.');
        redirect($back);
    }
    $stored = $result;
}

if ($stored === null && $reference === '') {
    flash('warning', 'Add a transfer reference or upload your slip so we can verify the payment.');
    redirect($back);
}

$receiptUrl = $stored !== null ? '/payment/proof.php?file=' . rawurlencode((string) $stored['file']) : null;

try {
    Database::tx(function () use ($order, $receiptUrl, $reference, $stored): void {
        if ($receiptUrl !== null) {
            Database::run('UPDATE orders SET receipt_url = :receipt, updated_at = :now WHERE id = :id', [
                'receipt' => $receiptUrl, 'now' => date('Y-m-d H:i:s'), 'id' => (int) $order['id'],
            ]);
        }
        $payment = Payments::create(
            (int) $order['id'],
            'bank_transfer',
            (float) $order['total'],
            (string) $order['currency'],
            'pending',
            $reference !== '' ? $reference : null,
            ['slip' => $stored['file'] ?? null, 'submitted_by' => Auth::id()],
            $receiptUrl
        );
        Orders::addEvent((int) $order['id'], (string) $order['status'], 'Payment proof submitted — awaiting verification.', true, 'customer', Auth::id());
        Notifier::notifyTeam(
            'Payment proof for ' . $order['order_number'],
            $order['customer_name'] . ' submitted a ' . ($receiptUrl !== null ? 'bank slip' : 'transfer reference') . '.',
            'payment',
            '/admin/payment.php?order=' . (int) $order['id']
        );
        if ($order['user_id'] !== null) {
            Notifier::notify((int) $order['user_id'], 'Payment proof received', 'We are verifying your payment for order ' . $order['order_number'] . '.', 'payment', '/account/order.php?id=' . (int) $order['id']);
        }
        Audit::log('payment.proof_submitted', 'order', (int) $order['id'], ['payment_id' => $payment, 'has_file' => $receiptUrl !== null]);
    });
} catch (Throwable $e) {
    error_log('Payment proof failed: ' . $e->getMessage());
    flash('danger', 'We could not save your proof. Please try again or contact support.');
    redirect($back);
}

try {
    Mailer::bankTransferReceived($order);
} catch (Throwable $e) {
    error_log('Bank transfer email failed: ' . $e->getMessage());
}

flash('success', 'Thank you — our finance team will verify your payment shortly.');
redirect($back);

<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!is_post()) {
    redirect('/account/index.php');
}
Csrf::verifyRequest();
Auth::requireLogin();

$user = Auth::user();
$userId = (int) $user['id'];
$action = (string) post('action', '');
$back = '/account/index.php';

switch ($action) {
    case 'profile':
        $back = '/account/settings.php';
        $name = trim((string) post('name', ''));
        $phone = trim((string) post('phone', ''));
        if (mb_strlen($name) < 2) {
            flash('danger', 'Please enter your full name.');
            redirect($back);
        }
        if ($phone !== '' && !is_valid_phone($phone)) {
            flash('danger', 'Enter a valid Sri Lankan phone number.');
            redirect($back);
        }
        Users::update($userId, [
            'name' => mb_substr($name, 0, 120),
            'phone' => $phone !== '' ? normalize_phone($phone) : null,
            'marketing_opt_in' => (int) (bool) post('marketing'),
        ]);
        Audit::log('account.profile_updated', 'user', $userId);
        flash('success', 'Your profile has been updated.');
        redirect($back);

    case 'password':
        $back = '/account/settings.php';
        $current = (string) post('current_password', '');
        $new = (string) post('new_password', '');
        $confirm = (string) post('new_password_confirm', '');
        if (!password_verify($current, (string) $user['password_hash'])) {
            flash('danger', 'Your current password is not correct.');
            redirect($back);
        }
        $errors = Auth::passwordErrors($new);
        if (hash_equals($current, $new)) {
            $errors[] = 'Choose a password different from your current password.';
        }
        if ($new !== $confirm) {
            $errors[] = 'The new passwords do not match.';
        }
        if ($errors !== []) {
            flash('danger', implode(' ', $errors));
            redirect($back);
        }
        Users::update($userId, [
            'password_hash' => Auth::hashPassword($new),
            'must_change_password' => 0,
        ]);
        Audit::log('account.password_changed', 'user', $userId);
        Notifier::notify($userId, 'Password changed', 'If this was not you, contact us immediately.', 'warning', '/account/settings.php');
        flash('success', 'Your password has been updated.');
        redirect($back);

    case 'address_create':
    case 'address_update':
        $back = '/account/addresses.php';
        $data = [
            'label' => (string) post('label', 'Home'),
            'full_name' => (string) post('full_name', ''),
            'phone' => (string) post('phone', ''),
            'line1' => (string) post('line1', ''),
            'line2' => (string) post('line2', ''),
            'city' => (string) post('city', ''),
            'district' => (string) post('district', ''),
            'postal_code' => (string) post('postal_code', ''),
            'country' => 'Sri Lanka',
            'is_default' => (int) (bool) post('is_default'),
        ];
        if ($data['full_name'] === '' || $data['line1'] === '' || $data['city'] === '' || $data['district'] === '' || !is_valid_phone($data['phone'])) {
            flash('danger', 'Please complete the address with a valid phone number.');
            redirect($back);
        }
        if ($action === 'address_create') {
            Addresses::create($userId, $data);
            flash('success', 'Address saved.');
        } else {
            Addresses::update(post_int('id'), $userId, $data);
            flash('success', 'Address updated.');
        }
        redirect($back);

    case 'address_delete':
        Addresses::delete(post_int('id'), $userId);
        flash('info', 'Address removed.');
        redirect('/account/addresses.php');

    case 'address_default':
        Addresses::setDefault(post_int('id'), $userId);
        flash('success', 'Default address updated.');
        redirect('/account/addresses.php');

    case 'cancel_order':
        $orderId = post_int('order_id');
        $back = '/account/order.php?id=' . $orderId;
        $order = Orders::find($orderId);
        if ($order === null || !Orders::ownedBy($order, $userId)) {
            abort(404, 'Order not found.');
        }
        if (!in_array($order['status'], ['PENDING', 'CONFIRMED'], true)) {
            flash('warning', 'This order can no longer be cancelled online. Please contact support.');
            redirect($back);
        }
        if ($order['payment_status'] === 'PAID') {
            flash('warning', 'This order is already paid. Please contact support to arrange a refund.');
            redirect($back);
        }
        $result = OrderService::updateStatus($orderId, 'CANCELLED', 'Cancelled by the customer.', 'customer', $userId);
        flash($result['ok'] ? 'success' : 'danger', $result['message']);
        redirect($back);

    default:
        flash('danger', 'Unknown action.');
        redirect($back);
}

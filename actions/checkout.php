<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!is_post()) {
    redirect('/checkout.php');
}
Csrf::verifyRequest();
RateLimiter::guard('order');

$cart = Cart::contents();
if ($cart['items'] === []) {
    flash('warning', 'Your cart is empty.');
    redirect('/cart.php');
}

$name = trim((string) post('customer_name', ''));
$email = strtolower(trim((string) post('email', '')));
$phone = trim((string) post('phone', ''));
$method = strtoupper(trim((string) post('payment_method', 'COD')));
$note = trim((string) post('note', ''));
$payment = PaymentManager::get($method);

$errors = [];
if (mb_strlen($name) < 3) {
    $errors[] = 'Please enter the recipient’s full name.';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please enter a valid email address.';
}
if (!is_valid_phone($phone)) {
    $errors[] = 'Please enter a valid Sri Lankan phone number.';
}
if ($payment === null || !$payment->enabled()) {
    $errors[] = 'Choose an available payment method.';
}
if (post('terms') === null) {
    $errors[] = 'Please accept the terms to continue.';
}

// A saved address wins over the typed fields when one was chosen.
$shipping = [
    'full_name'   => $name,
    'phone'       => $phone,
    'line1'       => trim((string) post('line1', '')),
    'line2'       => trim((string) post('line2', '')),
    'city'        => trim((string) post('city', '')),
    'district'    => trim((string) post('district', '')),
    'postal_code' => trim((string) post('postal_code', '')),
    'country'     => 'Sri Lanka',
];
$addressId = post_int('address_id');
if ($addressId > 0 && Auth::check()) {
    $saved = Addresses::find($addressId, (int) Auth::id());
    if ($saved !== null) {
        $shipping = [
            'full_name'   => $saved['full_name'],
            'phone'       => $saved['phone'],
            'line1'       => $saved['line1'],
            'line2'       => $saved['line2'],
            'city'        => $saved['city'],
            'district'    => $saved['district'],
            'postal_code' => $saved['postal_code'],
            'country'     => $saved['country'] ?: 'Sri Lanka',
        ];
        $name = $name !== '' ? $name : $saved['full_name'];
        $phone = $phone !== '' ? $phone : $saved['phone'];
    }
}

if ($shipping['line1'] === '' || mb_strlen($shipping['line1']) < 5) {
    $errors[] = 'Please enter the delivery address.';
}
if ($shipping['city'] === '') {
    $errors[] = 'Please enter your city or town.';
}
if ($shipping['district'] === '') {
    $errors[] = 'Please choose your district.';
}

if ($errors !== []) {
    remember_input([
        'customer_name' => $name, 'email' => $email, 'phone' => $phone,
        'line1' => $shipping['line1'], 'line2' => $shipping['line2'], 'city' => $shipping['city'],
        'district' => $shipping['district'], 'postal_code' => $shipping['postal_code'], 'note' => $note,
    ]);
    flash('danger', implode(' ', array_unique($errors)));
    redirect('/checkout.php');
}

$couponCode = (string) ($_SESSION['coupon_code'] ?? '');

$result = OrderService::place([
    'customer_name'  => $name,
    'email'          => $email,
    'phone'          => $phone,
    'shipping'       => $shipping,
    'billing'        => $shipping,
    'payment_method' => $method,
    'coupon_code'    => $couponCode,
    'note'           => $note,
]);

if (!$result['ok']) {
    remember_input([
        'customer_name' => $name, 'email' => $email, 'phone' => $phone,
        'line1' => $shipping['line1'], 'line2' => $shipping['line2'], 'city' => $shipping['city'],
        'district' => $shipping['district'], 'postal_code' => $shipping['postal_code'], 'note' => $note,
    ]);
    flash('danger', $result['error']);
    redirect('/checkout.php');
}

unset($_SESSION['coupon_code']);

// Keep the guest's order reachable via a signed token.
$order = Orders::find((int) $result['order_id']);
$token = $order !== null ? Security::sign(['order' => (int) $order['id'], 'n' => $order['order_number']], 60 * 60 * 24 * 30) : '';
if ($order !== null) {
    $_SESSION['last_order'] = ['id' => (int) $order['id'], 'token' => $token];
}

$gateway = PaymentManager::get($method);
if ($gateway !== null && $gateway->requiresRedirect() && $order !== null) {
    redirect('/payment/start.php?order=' . rawurlencode((string) $order['uuid']) . '&token=' . rawurlencode($token));
}
if ($method === 'BANK_TRANSFER') {
    flash('info', 'Order placed. Transfer the amount and upload your slip so we can dispatch.');
}

redirect('/order-success.php?order=' . rawurlencode((string) ($order['uuid'] ?? '')) . '&token=' . rawurlencode($token));

<?php
declare(strict_types=1);

/**
 * Order lifecycle: placing an order, moving it through the status machine,
 * keeping stock, payments, notifications and the audit trail in sync.
 */
final class OrderService
{
    /**
     * Place an order from the current cart.
     *
     * @param array<string,mixed> $input customer_name, email, phone, shipping,
     *        billing, payment_method, note, user_id, receipt_url
     * @return array{ok:bool,order_id?:int,order_number?:string,redirect?:string,error?:string}
     */
    public static function place(array $input): array
    {
        $contents = Cart::contents();
        if ($contents['items'] === []) {
            return ['ok' => false, 'error' => 'Your cart is empty.'];
        }

        $method = strtoupper((string) ($input['payment_method'] ?? 'COD'));
        if (!PaymentManager::isEnabled($method)) {
            return ['ok' => false, 'error' => 'That payment method is not available right now.'];
        }

        $userId = Auth::check() ? Auth::id() : null;
        if ($userId === null && !Settings::bool('allow_guest_checkout', true)) {
            return ['ok' => false, 'error' => 'Please sign in to complete your order.'];
        }

        $coupon = null;
        if (!empty($input['coupon_code'])) {
            $check = Coupons::validate((string) $input['coupon_code'], [
                'subtotal' => $contents['subtotal'],
                'user_id' => $userId,
                'items' => $contents['items'],
            ]);
            if (!$check['valid']) {
                return ['ok' => false, 'error' => $check['message']];
            }
            $coupon = $check['coupon'] + ['discount' => $check['discount'], 'free_shipping' => $check['free_shipping']];
        }

        $quote = Pricing::quote($contents, $coupon, $method);
        $gateway = PaymentManager::get($method);
        if ($gateway === null || !$gateway->isAvailable($quote['total'])) {
            return ['ok' => false, 'error' => 'That payment method is not available for this order value.'];
        }

        $shipping = (array) ($input['shipping'] ?? []);
        if (empty($shipping['line1']) || empty($shipping['city']) || empty($shipping['full_name']) || empty($shipping['phone'])) {
            return ['ok' => false, 'error' => 'Please complete the delivery address.'];
        }
        $billing = !empty($input['billing']) ? (array) $input['billing'] : $shipping;

        self::validateCustomer($input);

        try {
            $result = Database::tx(function () use ($contents, $input, $method, $quote, $coupon, $userId, $shipping, $billing): array {
                // Lock every product row and re-price from the database so the
                // amount charged cannot be manipulated from the browser.
                $locked = [];
                $lockedSubtotal = 0.0;
                foreach ($contents['items'] as $line) {
                    $productId = (int) $line['product']['id'];
                    $row = Database::one(
                        "SELECT id, title, slug, sku, price, stock, image_url, category_id, free_shipping, weight_grams
                           FROM products WHERE id = :id AND status = 'active' AND deleted_at IS NULL FOR UPDATE",
                        ['id' => $productId]
                    );
                    if ($row === null) {
                        throw new RuntimeException('One of the items is no longer available. Please review your cart.');
                    }
                    $quantity = (int) $line['quantity'];
                    if ((int) $row['stock'] < $quantity) {
                        throw new RuntimeException('Only ' . (int) $row['stock'] . ' unit(s) of “' . $row['title'] . '” remain.');
                    }
                    $lineTotal = round((float) $row['price'] * $quantity, 2);
                    $locked[] = [
                        'product' => [
                            'id' => (int) $row['id'],
                            'category_id' => (int) $row['category_id'],
                            'free_shipping' => (int) $row['free_shipping'],
                            'weight_grams' => (float) ($row['weight_grams'] ?? 0),
                        ],
                        'quantity' => $quantity,
                        'line_total' => $lineTotal,
                        'row' => $row,
                    ];
                    $lockedSubtotal += $lineTotal;
                }

                $lockedQuote = Pricing::quote(
                    ['items' => $locked, 'subtotal' => round($lockedSubtotal, 2)],
                    $coupon,
                    $method
                );

                $now = date('Y-m-d H:i:s');
                $tempNumber = 'TMP-' . strtoupper(bin2hex(random_bytes(6)));
                $orderId = Database::insert('orders', [
                    'uuid' => uuid(),
                    'order_number' => $tempNumber,
                    'user_id' => $userId,
                    'customer_name' => substr(trim((string) $input['customer_name']), 0, 120),
                    'email' => strtolower(trim((string) $input['email'])),
                    'phone' => normalize_phone((string) $input['phone']),
                    'status' => 'PENDING',
                    'payment_status' => 'UNPAID',
                    'payment_method' => $method,
                    'subtotal' => $lockedQuote['subtotal'],
                    'shipping_fee' => $lockedQuote['shipping'],
                    'discount' => $lockedQuote['discount'],
                    'tax' => $lockedQuote['tax'],
                    'total' => $lockedQuote['total'],
                    'currency' => (string) Config::get('app.currency', 'LKR'),
                    'coupon_code' => $coupon['code'] ?? null,
                    'coupon_id' => isset($coupon['id']) ? (int) $coupon['id'] : null,
                    'shipping_address' => json_encode($shipping, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'billing_address' => json_encode($billing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'note' => isset($input['note']) && $input['note'] !== '' ? substr((string) $input['note'], 0, 500) : null,
                    'receipt_url' => $input['receipt_url'] ?? null,
                    'ip_address' => Security::clientIp(),
                    'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
                    'placed_at' => $now,
                    'updated_at' => $now,
                ]);

                $prefix = Settings::get('order_prefix', 'SMM');
                $orderNumber = $prefix . '-' . date('Y') . '-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
                Database::run('UPDATE orders SET order_number = :n WHERE id = :id', ['n' => $orderNumber, 'id' => $orderId]);

                foreach ($locked as $line) {
                    $row = $line['row'];
                    Database::insert('order_items', [
                        'order_id' => $orderId,
                        'product_id' => (int) $row['id'],
                        'title' => (string) $row['title'],
                        'slug' => (string) $row['slug'],
                        'image_url' => $row['image_url'],
                        'sku' => (string) $row['sku'],
                        'unit_price' => (float) $row['price'],
                        'quantity' => (int) $line['quantity'],
                        'total' => (float) $line['line_total'],
                    ]);

                    $updated = Database::run(
                        'UPDATE products SET stock = stock - :qty, sold_count = sold_count + :qty2, updated_at = :now
                          WHERE id = :id AND stock >= :qty3',
                        ['qty' => (int) $line['quantity'], 'qty2' => (int) $line['quantity'], 'now' => $now, 'id' => (int) $row['id'], 'qty3' => (int) $line['quantity']]
                    )->rowCount();
                    if ($updated !== 1) {
                        throw new RuntimeException('Stock changed while you were checking out. Please review your cart.');
                    }
                    Inventory::record((int) $row['id'], -(int) $line['quantity'], 'order', 'Order ' . $orderNumber, $orderNumber);
                }

                if ($coupon !== null && isset($coupon['id'])) {
                    Coupons::recordRedemption((int) $coupon['id'], $userId, $orderId, (float) $lockedQuote['discount']);
                }

                Payments::create(
                    $orderId,
                    PaymentManager::keyForMethod($method),
                    (float) $lockedQuote['total'],
                    (string) Config::get('app.currency', 'LKR'),
                    'pending',
                    null,
                    ['method' => $method]
                );

                Orders::addEvent($orderId, 'PENDING', 'Order placed. We will confirm it shortly.', true, 'customer', $userId);
                Orders::addEvent($orderId, 'PENDING', 'Payment method: ' . payment_method_label($method), false, 'system');

                if ($userId !== null) {
                    Notifier::notify(
                        $userId,
                        'Order ' . $orderNumber . ' received',
                        'Thanks! We are preparing your order. You can follow every step from your account.',
                        'order',
                        '/account/order.php?id=' . $orderId
                    );
                }
                Notifier::notifyTeam(
                    'New order ' . $orderNumber,
                    money((float) $lockedQuote['total']) . ' · ' . $input['customer_name'] . ' · ' . payment_method_label($method),
                    'order',
                    '/admin/order.php?id=' . $orderId
                );

                return ['order_id' => $orderId, 'order_number' => $orderNumber, 'total' => $lockedQuote['total']];
            });
        } catch (Throwable $e) {
            error_log('Order placement failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage() !== '' ? $e->getMessage() : 'We could not place the order. Please try again.'];
        }

        Cart::clear();
        Audit::log('order.placed', 'order', $result['order_id'], ['number' => $result['order_number'], 'method' => $method]);

        $order = Orders::find($result['order_id']);
        if ($order !== null) {
            $items = Orders::items($result['order_id']);
            try {
                Mailer::orderConfirmation($order, $items);
            } catch (Throwable $e) {
                error_log('Order email failed: ' . $e->getMessage());
            }

            $token = Security::sign(['order' => $result['order_id'], 'n' => $result['order_number']], 60 * 60 * 24 * 30);

            // Hosted gateways send the shopper straight to the provider.
            $gateway = PaymentManager::get($method);
            if ($gateway !== null && $gateway->requiresRedirect()) {
                return [
                    'ok' => true,
                    'order_id' => $result['order_id'],
                    'order_number' => $result['order_number'],
                    'redirect' => '/payment/start.php?order=' . rawurlencode((string) $order['uuid']) . '&token=' . rawurlencode($token),
                ];
            }

            return [
                'ok' => true,
                'order_id' => $result['order_id'],
                'order_number' => $result['order_number'],
                'redirect' => '/order-success.php?order=' . rawurlencode((string) $order['uuid']) . '&token=' . rawurlencode($token),
            ];
        }

        return ['ok' => true, 'order_id' => $result['order_id'], 'order_number' => $result['order_number'], 'redirect' => '/'];
    }

    /** @throws RuntimeException */
    private static function validateCustomer(array $input): void
    {
        if (trim((string) ($input['customer_name'] ?? '')) === '') {
            throw new RuntimeException('Please enter the recipient name.');
        }
        if (!filter_var((string) ($input['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please enter a valid email address.');
        }
        if (!is_valid_phone((string) ($input['phone'] ?? ''))) {
            throw new RuntimeException('Please enter a valid Sri Lankan mobile number (e.g. 077 123 4567).');
        }
    }

    /**
     * Move an order to a new status.
     *
     * @return array{ok:bool,message:string}
     */
    public static function updateStatus(int $orderId, string $status, string $message, string $actorType = 'staff', ?int $actorId = null, bool $force = false): array
    {
        $order = Orders::find($orderId);
        if ($order === null) {
            return ['ok' => false, 'message' => 'Order not found.'];
        }
        $status = strtoupper($status);
        if (!array_key_exists($status, order_statuses())) {
            return ['ok' => false, 'message' => 'Unknown order status.'];
        }
        if ($status === $order['status']) {
            return ['ok' => false, 'message' => 'The order is already ' . order_status_label($status) . '.'];
        }
        if (!$force && !Orders::canTransition((string) $order['status'], $status)) {
            return ['ok' => false, 'message' => 'An order cannot move from ' . order_status_label((string) $order['status']) . ' to ' . order_status_label($status) . '.'];
        }

        try {
            Database::tx(function () use ($order, $status, $message, $actorType, $actorId): void {
                $now = date('Y-m-d H:i:s');
                $fields = ['status' => $status, 'updated_at' => $now];
                $timestampField = match ($status) {
                    'CONFIRMED' => 'confirmed_at',
                    'PACKED' => 'packed_at',
                    'SHIPPED' => 'shipped_at',
                    'DELIVERED' => 'delivered_at',
                    'CANCELLED' => 'cancelled_at',
                    default => null,
                };
                if ($timestampField !== null) {
                    $fields[$timestampField] = $now;
                }
                Database::update('orders', $fields, 'id = :id', ['id' => (int) $order['id']]);

                if (in_array($status, ['CANCELLED', 'RETURNED'], true)) {
                    self::restock((int) $order['id']);
                }

                if ($status === 'DELIVERED' && $order['payment_method'] === 'COD' && $order['payment_status'] !== 'PAID') {
                    $payment = Orders::latestPayment((int) $order['id']);
                    if ($payment !== null) {
                        Payments::markPaid((int) $payment['id'], null, ['cod_collected' => true], $actorType === 'admin' || $actorType === 'staff' ? $actorId : null);
                    }
                }

                Orders::addEvent((int) $order['id'], $status, $message !== '' ? $message : 'Status updated to ' . order_status_label($status) . '.', true, $actorType, $actorId);

                if ($order['user_id'] !== null) {
                    Notifier::notify(
                        (int) $order['user_id'],
                        'Order ' . $order['order_number'] . ' — ' . order_status_label($status),
                        $message !== '' ? $message : 'Your order is now ' . strtolower(order_status_label($status)) . '.',
                        $status === 'CANCELLED' ? 'danger' : 'order',
                        '/account/order.php?id=' . (int) $order['id']
                    );
                }
            });
        } catch (Throwable $e) {
            error_log('Order status update failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        Audit::log('order.status', 'order', $orderId, ['from' => $order['status'], 'to' => $status]);
        $fresh = Orders::find($orderId);
        if ($fresh !== null) {
            try {
                Mailer::orderStatus($fresh, $status, $message);
            } catch (Throwable $e) {
                error_log('Order status email failed: ' . $e->getMessage());
            }
        }

        return ['ok' => true, 'message' => 'Order marked as ' . order_status_label($status) . '.'];
    }

    /** Put cancelled/returned items back on the shelf (once). */
    private static function restock(int $orderId): void
    {
        $already = Database::exists(
            "SELECT 1 FROM order_events WHERE order_id = :oid AND status IN ('CANCELLED', 'RETURNED') LIMIT 1",
            ['oid' => $orderId]
        );
        if ($already) {
            return;
        }
        foreach (Orders::items($orderId) as $item) {
            if ($item['product_id'] === null) {
                continue;
            }
            Database::run(
                'UPDATE products SET stock = stock + :qty, sold_count = GREATEST(0, sold_count - :qty2), updated_at = :now WHERE id = :id',
                [
                    'qty' => (int) $item['quantity'],
                    'qty2' => (int) $item['quantity'],
                    'now' => date('Y-m-d H:i:s'),
                    'id' => (int) $item['product_id'],
                ]
            );
            Inventory::record((int) $item['product_id'], (int) $item['quantity'], 'return', 'Restocked from order #' . $orderId);
        }
    }

    public static function updateTracking(int $orderId, ?string $trackingNumber, ?string $carrier, ?string $courierUrl, string $message = ''): void
    {
        Database::update('orders', [
            'tracking_number' => $trackingNumber !== null && $trackingNumber !== '' ? substr($trackingNumber, 0, 80) : null,
            'carrier' => $carrier !== null && $carrier !== '' ? substr($carrier, 0, 80) : null,
            'courier_url' => $courierUrl !== null && $courierUrl !== '' ? substr($courierUrl, 0, 255) : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $orderId]);

        if ($message !== '') {
            Orders::addEvent($orderId, 'SHIPPED', $message, true, 'staff', Auth::id());
        }
        Audit::log('order.tracking', 'order', $orderId, ['carrier' => $carrier, 'tracking' => $trackingNumber]);
    }

    /** Customer-facing live snapshot used by the tracking poller. */
    public static function snapshot(int $orderId): ?array
    {
        $order = Orders::find($orderId);
        if ($order === null) {
            return null;
        }
        $events = Orders::events($orderId, true);
        return [
            'order_number' => $order['order_number'],
            'status' => $order['status'],
            'status_label' => order_status_label((string) $order['status']),
            'payment_status' => $order['payment_status'],
            'payment_status_label' => payment_status_label((string) $order['payment_status']),
            'progress' => $order['progress'],
            'tracking_number' => $order['tracking_number'],
            'carrier' => $order['carrier'],
            'courier_url' => $order['courier_url'],
            'updated_at' => $order['updated_at'],
            'updated_human' => time_ago((string) $order['updated_at']),
            'events' => array_map(static fn (array $event): array => [
                'status' => $event['status'],
                'label' => order_status_label((string) $event['status']),
                'message' => $event['message'],
                'at' => fmt_datetime((string) $event['created_at']),
            ], $events),
        ];
    }

    /** Public tracking lookup: order number + email or phone. */
    public static function publicLookup(string $orderNumber, string $contact): ?array
    {
        $order = Orders::findByNumber($orderNumber);
        if ($order === null) {
            return null;
        }
        $contact = strtolower(trim($contact));
        $email = strtolower((string) $order['email']);
        $phone = normalize_phone((string) $order['phone']);
        $contactPhone = normalize_phone($contact);
        if ($contact !== $email && ($contactPhone === '' || $contactPhone !== $phone)) {
            return null;
        }
        return $order;
    }
}

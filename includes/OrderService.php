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
        self::expireStaleGatewayAttempts();
        $contents = Cart::contents();
        if ($contents['unavailable'] !== []) {
            $names = array_map(static fn (array $item): string => (string) $item['title'], $contents['unavailable']);
            return ['ok' => false, 'error' => 'Some selected items are no longer available in the requested quantity: ' . implode(', ', $names) . '. Update your cart before placing the order.'];
        }
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
        $requiresOnlinePayment = $gateway->requiresRedirect();
        $cartSnapshot = $requiresOnlinePayment ? Cart::raw() : [];

        $shipping = (array) ($input['shipping'] ?? []);
        if (empty($shipping['line1']) || empty($shipping['city']) || empty($shipping['full_name']) || empty($shipping['phone'])) {
            return ['ok' => false, 'error' => 'Please complete the delivery address.'];
        }
        $billing = !empty($input['billing']) ? (array) $input['billing'] : $shipping;

        self::validateCustomer($input);

        try {
            $result = Database::tx(function () use ($contents, $input, $method, $quote, $coupon, $userId, $shipping, $billing, $requiresOnlinePayment): array {
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
                    'payment_status' => $requiresOnlinePayment ? 'PENDING' : 'UNPAID',
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

                $prefix = Settings::get('order_prefix', 'YM');
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

                if ($requiresOnlinePayment) {
                    Orders::addEvent($orderId, 'PENDING', 'Checkout is awaiting verified payment; this is not yet a placed order.', false, 'system');
                } else {
                    Orders::addEvent($orderId, 'PENDING', 'Order placed. We will confirm it shortly.', true, 'customer', $userId);
                }
                Orders::addEvent($orderId, 'PENDING', 'Payment method: ' . payment_method_label($method), false, 'system');

                return ['order_id' => $orderId, 'order_number' => $orderNumber, 'total' => $lockedQuote['total']];
            });
        } catch (Throwable $e) {
            error_log('Order placement failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage() !== '' ? $e->getMessage() : 'We could not place the order. Please try again.'];
        }

        if ($requiresOnlinePayment) {
            Cart::rememberPendingCheckout((int) $result['order_id'], $cartSnapshot);
            Audit::log('payment.checkout_started', 'checkout', $result['order_id'], ['number' => $result['order_number'], 'method' => $method]);
        } else {
            Cart::clear();
            Audit::log('order.placed', 'order', $result['order_id'], ['number' => $result['order_number'], 'method' => $method]);
        }

        $order = Orders::find($result['order_id']);
        if ($order !== null) {
            if (!$requiresOnlinePayment) {
                $items = Orders::items($result['order_id']);
                try {
                    Mailer::orderConfirmation($order, $items);
                } catch (Throwable $e) {
                    error_log('Order email failed: ' . $e->getMessage());
                }
                if ($userId !== null) {
                    Notifier::notify($userId, 'Order ' . $order['order_number'] . ' received', 'Thanks! We are preparing your order. You can follow every step from your account.', 'order', '/account/order.php?id=' . $result['order_id']);
                }
                Notifier::notifyTeam('New order ' . $order['order_number'], money((float) $order['total']) . ' · ' . $order['customer_name'] . ' · ' . payment_method_label($method), 'order', '/admin/order.php?id=' . $result['order_id']);
            }

            $token = Security::sign(['order' => $result['order_id'], 'n' => $result['order_number']], 60 * 60 * 24 * 30);

            // Hosted gateways receive a provisional payment reference. The
            // customer order is only confirmed after a verified success callback.
            if ($requiresOnlinePayment) {
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

    /** Release abandoned online-checkout reservations once they are 24 hours old. */
    public static function expireStaleGatewayAttempts(int $limit = 50): int
    {
        $rows = Database::all(
            "SELECT id FROM orders
              WHERE payment_status = 'PENDING' AND status = 'PENDING'
                AND payment_method NOT IN ('COD','BANK_TRANSFER')
                AND placed_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
           ORDER BY placed_at ASC LIMIT " . max(1, min(200, $limit))
        );
        $expired = 0;
        foreach ($rows as $row) {
            if (self::discardUnpaidGatewayAttempt((int) $row['id'], null, ['source' => 'checkout_expired'])) {
                Cart::forgetPendingCheckout((int) $row['id']);
                $expired++;
            }
        }
        return $expired;
    }

    /** Automatically cancel bank-transfer orders that have no uploaded receipt after one hour. */
    public static function expireOverdueBankTransfers(int $limit = 100): int
    {
        $rows = Database::all(
            "SELECT id FROM orders
              WHERE payment_method = 'BANK_TRANSFER'
                AND payment_status IN ('UNPAID','PENDING')
                AND receipt_url IS NULL
                AND status NOT IN ('CANCELLED','RETURNED','REFUNDED','DELIVERED')
                AND placed_at <= DATE_SUB(NOW(), INTERVAL 1 HOUR)
           ORDER BY placed_at ASC LIMIT " . max(1, min(500, $limit))
        );
        $cancelled = 0;
        $reason = 'Automatically canceled because payment was not completed and no bank transfer receipt was uploaded within one hour.';
        foreach ($rows as $row) {
            $cancelledOrderId = Database::tx(static function () use ($row, $reason): ?int {
                $order = Database::one(
                    "SELECT id, user_id, order_number, status, payment_status, payment_method, receipt_url,
                            (placed_at <= DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS overdue
                       FROM orders WHERE id = :id FOR UPDATE",
                    ['id' => (int) $row['id']]
                );
                if ($order === null
                    || $order['payment_method'] !== 'BANK_TRANSFER'
                    || $order['receipt_url'] !== null
                    || (int) $order['overdue'] !== 1
                    || !in_array((string) $order['payment_status'], ['UNPAID', 'PENDING'], true)
                    || in_array((string) $order['status'], ['CANCELLED', 'RETURNED', 'REFUNDED', 'DELIVERED'], true)) {
                    return null;
                }
                $now = date('Y-m-d H:i:s');
                Database::run(
                    'UPDATE orders SET status = :status, cancelled_at = :cancelled, updated_at = :updated WHERE id = :id',
                    ['status' => 'CANCELLED', 'cancelled' => $now, 'updated' => $now, 'id' => (int) $order['id']]
                );
                Database::run(
                    "UPDATE payments SET status = 'failed', response_payload = :response, updated_at = :now WHERE order_id = :id AND status IN ('pending','submitted')",
                    ['response' => json_encode(['source' => 'bank_transfer_receipt_timeout'], JSON_UNESCAPED_SLASHES), 'now' => $now, 'id' => (int) $order['id']]
                );
                self::restock((int) $order['id']);
                Orders::addEvent((int) $order['id'], 'CANCELLED', $reason, true, 'system');
                if ($order['user_id'] !== null) {
                    Notifier::notify(
                        (int) $order['user_id'],
                        'Order ' . $order['order_number'] . ' cancelled',
                        $reason,
                        'danger',
                        '/account/order.php?id=' . (int) $order['id']
                    );
                }
                Notifier::notifyTeam(
                    'Bank transfer order ' . $order['order_number'] . ' auto-cancelled',
                    $reason,
                    'order',
                    '/admin/order.php?id=' . (int) $order['id']
                );
                Audit::log('order.auto_cancelled', 'order', (int) $order['id'], ['reason' => $reason, 'trigger' => 'bank_transfer_receipt_timeout']);
                return (int) $order['id'];
            });
            if ($cancelledOrderId !== null) {
                $cancelled++;
                $fresh = Orders::find($cancelledOrderId);
                if ($fresh !== null) {
                    try {
                        Mailer::orderStatus($fresh, 'CANCELLED', $reason);
                    } catch (Throwable $e) {
                        error_log('Auto-cancellation email failed: ' . $e->getMessage());
                    }
                }
            }
        }
        return $cancelled;
    }

    /**
     * Remove a failed hosted-payment attempt so it is not an order. Restore its
     * reserved stock and coupon use atomically; retain only an audit record.
     */
    public static function discardUnpaidGatewayAttempt(int $orderId, ?int $paymentId = null, array $context = []): bool
    {
        return Database::tx(static function () use ($orderId, $paymentId, $context): bool {
            $order = Database::one('SELECT id, order_number, payment_method, payment_status, status, coupon_id, total FROM orders WHERE id = :id FOR UPDATE', ['id' => $orderId]);
            if ($order === null || strtoupper((string) $order['payment_status']) === 'PAID') {
                return false;
            }
            $gateway = PaymentManager::get((string) $order['payment_method']);
            if ($gateway === null || !$gateway->requiresRedirect()) {
                return false;
            }

            $payment = $paymentId !== null
                ? Database::one('SELECT * FROM payments WHERE id = :pid AND order_id = :oid FOR UPDATE', ['pid' => $paymentId, 'oid' => $orderId])
                : Database::one('SELECT * FROM payments WHERE order_id = :oid ORDER BY id DESC LIMIT 1 FOR UPDATE', ['oid' => $orderId]);
            if ($payment !== null && strtolower((string) $payment['status']) === 'paid') {
                return false;
            }
            if ($payment !== null) {
                Database::update('payments', [
                    'status' => 'failed',
                    'response_payload' => $context === [] ? null : json_encode($context, JSON_UNESCAPED_SLASHES),
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id = :id', ['id' => (int) $payment['id']]);
            }

            if (!in_array(strtoupper((string) $order['status']), ['CANCELLED', 'RETURNED'], true)) {
                foreach (Database::all('SELECT product_id, quantity FROM order_items WHERE order_id = :oid', ['oid' => $orderId]) as $item) {
                    $productId = (int) ($item['product_id'] ?? 0);
                    $quantity = max(0, (int) ($item['quantity'] ?? 0));
                    if ($productId <= 0 || $quantity <= 0) {
                        continue;
                    }
                    Database::run(
                        'UPDATE products SET stock = stock + :qty, sold_count = GREATEST(0, sold_count - :sold), updated_at = :now WHERE id = :id',
                        ['qty' => $quantity, 'sold' => $quantity, 'now' => date('Y-m-d H:i:s'), 'id' => $productId]
                    );
                    Inventory::record($productId, $quantity, 'payment_failed', 'Unpaid checkout attempt discarded', (string) $order['order_number']);
                }
            }

            if (!empty($order['coupon_id'])) {
                Database::run('UPDATE coupons SET used_count = IF(used_count > 0, used_count - 1, 0) WHERE id = :id', ['id' => (int) $order['coupon_id']]);
                Database::run('DELETE FROM coupon_redemptions WHERE order_id = :oid', ['oid' => $orderId]);
            }

            Audit::log('payment.failed', 'checkout', $orderId, [
                'gateway' => $gateway->key,
                'amount' => (float) $order['total'],
                'reference' => $payment['gateway_reference'] ?? null,
                'source' => (string) ($context['source'] ?? 'payment'),
            ]);
            Database::run('DELETE FROM orders WHERE id = :id', ['id' => $orderId]);
            Cart::forgetPendingCheckout($orderId);
            return true;
        });
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
        if ($status === 'RETURNED' && Database::exists(
            "SELECT 1 FROM return_requests WHERE order_id = :id AND status NOT IN ('REJECTED','CANCELLED') LIMIT 1",
            ['id' => $orderId]
        )) {
            return ['ok' => false, 'message' => 'This order has a return case. Complete stock/refund handling through the return request to avoid processing the same items twice.'];
        }
        $paymentGateway = PaymentManager::get((string) $order['payment_method']);
        if ($paymentGateway !== null && $paymentGateway->requiresRedirect() && strtoupper((string) $order['payment_status']) !== 'PAID') {
            if ($status !== 'CANCELLED') {
                return ['ok' => false, 'message' => 'Payment must be verified before an online order can be fulfilled.'];
            }
            $payment = Orders::latestPayment($orderId);
            if (self::discardUnpaidGatewayAttempt($orderId, $payment !== null ? (int) $payment['id'] : null, ['source' => 'staff_cancelled'])) {
                return ['ok' => true, 'message' => 'Unpaid checkout removed; reserved stock and coupon usage were restored.'];
            }
            return ['ok' => false, 'message' => 'This unpaid checkout could not be removed. Refresh the order and try again.'];
        }
        if ((string) $order['payment_method'] === 'BANK_TRANSFER'
            && strtoupper((string) $order['payment_status']) !== 'PAID'
            && $status !== 'CANCELLED') {
            return ['ok' => false, 'message' => 'Verify the bank transfer before moving this order into fulfillment.'];
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

<?php
declare(strict_types=1);

/**
 * Payment records for every gateway.
 *
 * A payment row is the audit trail: what the gateway told us, when, and who
 * verified it. Order payment_status is a summary that only changes here.
 */
final class Payments
{
    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM payments WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public static function findByReference(string $reference, ?string $gateway = null): ?array
    {
        $sql = 'SELECT * FROM payments WHERE gateway_reference = :ref';
        $params = ['ref' => $reference];
        if ($gateway !== null) {
            $sql .= ' AND gateway = :gw';
            $params['gw'] = $gateway;
        }
        return Database::one($sql . ' ORDER BY id DESC LIMIT 1', $params);
    }

    /** @return list<array<string,mixed>> */
    public static function forOrder(int $orderId): array
    {
        return Database::all('SELECT * FROM payments WHERE order_id = :oid ORDER BY id DESC', ['oid' => $orderId]);
    }

    /** @return array<string,mixed>|null */
    public static function pendingForOrder(int $orderId): ?array
    {
        return Database::one(
            "SELECT * FROM payments WHERE order_id = :oid AND status IN ('pending', 'initiated') ORDER BY id DESC LIMIT 1",
            ['oid' => $orderId]
        );
    }

    public static function create(int $orderId, string $gateway, float $amount, string $currency, string $status = 'pending', ?string $reference = null, array $payload = [], ?string $receiptUrl = null): int
    {
        return Database::insert('payments', [
            'order_id'          => $orderId,
            'gateway'           => substr(strtolower($gateway), 0, 30),
            'gateway_reference' => $reference !== null ? substr($reference, 0, 120) : null,
            'amount'            => $amount,
            'currency'          => $currency,
            'status'            => substr(strtolower($status), 0, 20),
            'request_payload'   => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_SLASHES),
            'response_payload'  => null,
            'receipt_url'       => $receiptUrl,
            'created_at'        => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);
    }

    public static function update(int $id, array $data): void
    {
        $allowed = ['status', 'gateway_reference', 'response_payload', 'receipt_url', 'verified_by', 'verified_at'];
        $clean = array_intersect_key($data, array_flip($allowed));
        if ($clean === []) {
            return;
        }
        $clean['updated_at'] = date('Y-m-d H:i:s');
        Database::update('payments', $clean, 'id = :id', ['id' => $id]);
    }

    /** Mark a payment as collected and mirror it on the order. */
    public static function markPaid(int $paymentId, ?string $reference = null, array $response = [], ?int $verifiedBy = null): void
    {
        $payment = self::find($paymentId);
        if ($payment === null) {
            throw new RuntimeException('Payment record not found.');
        }
        $order = Orders::find((int) $payment['order_id']);
        if ($order === null) {
            throw new RuntimeException('Order not found for this payment.');
        }

        $gateway = PaymentManager::get((string) $order['payment_method']);
        $onlineCheckout = $gateway !== null && $gateway->requiresRedirect();
        $newlyPlaced = Database::tx(static function () use ($payment, $paymentId, $reference, $response, $verifiedBy, $order): bool {
            $lockedPayment = Database::one('SELECT status FROM payments WHERE id = :pid FOR UPDATE', ['pid' => $paymentId]);
            $lockedOrder = Database::one('SELECT id, status, user_id, order_number, payment_method FROM orders WHERE id = :oid FOR UPDATE', ['oid' => (int) $order['id']]);
            if ($lockedPayment === null || $lockedOrder === null || strtolower((string) $lockedPayment['status']) === 'paid') {
                return false;
            }
            self::update($paymentId, [
                'status' => 'paid',
                'gateway_reference' => $reference ?? $payment['gateway_reference'],
                'response_payload' => $response === [] ? $payment['response_payload'] : json_encode($response, JSON_UNESCAPED_SLASHES),
                'verified_by' => $verifiedBy,
                'verified_at' => date('Y-m-d H:i:s'),
            ]);
            Database::run(
                'UPDATE orders SET payment_status = :ps, paid_at = COALESCE(paid_at, :now), updated_at = :now2 WHERE id = :oid',
                ['ps' => 'PAID', 'now' => date('Y-m-d H:i:s'), 'now2' => date('Y-m-d H:i:s'), 'oid' => (int) $order['id']]
            );
            $wasPending = strtoupper((string) $lockedOrder['status']) === 'PENDING';
            if ($wasPending) {
                Database::run('UPDATE orders SET status = :status WHERE id = :oid', ['status' => 'CONFIRMED', 'oid' => (int) $order['id']]);
                Orders::addEvent((int) $order['id'], 'CONFIRMED', 'Payment received — order confirmed.', true, 'system');
            } else {
                Orders::addEvent((int) $order['id'], (string) $lockedOrder['status'], 'Payment received (' . money((float) $payment['amount']) . ').', true, 'system');
            }
            if ($lockedOrder['user_id'] !== null) {
                Notifier::notify(
                    (int) $lockedOrder['user_id'],
                    'Payment received',
                    'We have received your payment of ' . money((float) $payment['amount']) . ' for order ' . $lockedOrder['order_number'] . '.',
                    'payment',
                    '/account/order.php?id=' . (int) $order['id']
                );
            }
            Audit::log('payment.paid', 'order', (int) $order['id'], ['gateway' => $payment['gateway'], 'amount' => (float) $payment['amount']]);
            return $wasPending;
        });
        if ($onlineCheckout && $newlyPlaced) {
            $confirmedOrder = Orders::find((int) $order['id']);
            if ($confirmedOrder !== null) {
                try {
                    Mailer::orderConfirmation($confirmedOrder, Orders::items((int) $order['id']));
                } catch (Throwable $e) {
                    error_log('Order confirmation email failed after payment: ' . $e->getMessage());
                }
                Notifier::notifyTeam(
                    'New paid order ' . $confirmedOrder['order_number'],
                    money((float) $confirmedOrder['total']) . ' · ' . $confirmedOrder['customer_name'] . ' · ' . payment_method_label((string) $confirmedOrder['payment_method']),
                    'order',
                    '/admin/order.php?id=' . (int) $confirmedOrder['id']
                );
            }
        }
    }

    public static function markFailed(int $paymentId, array $response = []): void
    {
        $payment = self::find($paymentId);
        if ($payment === null || strtolower((string) $payment['status']) === 'paid') {
            return;
        }
        $order = Orders::find((int) $payment['order_id']);
        $gateway = $order !== null ? PaymentManager::get((string) $order['payment_method']) : null;
        if ($order !== null && $gateway !== null && $gateway->requiresRedirect()) {
            OrderService::discardUnpaidGatewayAttempt(
                (int) $order['id'],
                $paymentId,
                ['source' => (string) ($response['source'] ?? 'provider_failure')]
            );
            return;
        }
        self::update($paymentId, [
            'status' => 'failed',
            'response_payload' => $response === [] ? null : json_encode($response, JSON_UNESCAPED_SLASHES),
        ]);
        Audit::log('payment.failed', 'order', (int) $payment['order_id'], ['gateway' => $payment['gateway']]);
    }

    /** Finance verifies a bank transfer / slip. */
    public static function verifyBankTransfer(int $paymentId, int $adminId): void
    {
        $payment = self::find($paymentId);
        if ($payment === null) {
            throw new RuntimeException('Payment not found.');
        }
        self::markPaid($paymentId, $payment['gateway_reference'], ['verified_manually' => true], $adminId);
        Audit::log('payment.bank_transfer.verified', 'payment', $paymentId, ['admin_id' => $adminId]);
    }

    /** @return list<array<string,mixed>> */
    public static function pendingVerification(int $limit = 20): array
    {
        return Database::all(
            "SELECT pay.*, o.order_number, o.customer_name, o.email, o.total AS order_total, o.status AS order_status
               FROM payments pay
               JOIN orders o ON o.id = pay.order_id
              WHERE pay.gateway = 'bank_transfer' AND pay.status IN ('pending', 'submitted')
           ORDER BY pay.created_at ASC LIMIT " . max(1, $limit)
        );
    }

    /** Totals per method for the payments report. */
    public static function summary(?string $from = null, ?string $to = null): array
    {
        $sql = "SELECT gateway, COUNT(*) AS c, COALESCE(SUM(amount), 0) AS t
                  FROM payments WHERE status = 'paid'";
        $params = [];
        if ($from !== null) {
            $sql .= ' AND created_at >= :from';
            $params['from'] = $from . ' 00:00:00';
        }
        if ($to !== null) {
            $sql .= ' AND created_at <= :to';
            $params['to'] = $to . ' 23:59:59';
        }
        $sql .= ' GROUP BY gateway ORDER BY t DESC';
        return Database::all($sql, $params);
    }

    public static function collectedTotal(?string $from = null, ?string $to = null): float
    {
        $sql = "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'paid'";
        $params = [];
        if ($from !== null) {
            $sql .= ' AND created_at >= :from';
            $params['from'] = $from . ' 00:00:00';
        }
        if ($to !== null) {
            $sql .= ' AND created_at <= :to';
            $params['to'] = $to . ' 23:59:59';
        }
        return Database::float($sql, $params);
    }
}

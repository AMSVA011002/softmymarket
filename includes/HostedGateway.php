<?php
declare(strict_types=1);

/**
 * Hosted checkout gateway used by Seylan Bank cards, KOKO, Payzy and Mintpay.
 *
 * All four providers follow the same shape: the store redirects the shopper to
 * the provider with a signed payload, the provider redirects back (and calls the
 * notify URL), and the store verifies the signature before trusting anything.
 *
 * Provider-specific field names live in requestPayload()/verifyCallback() so a
 * change to one provider's spec never touches the order flow.
 */
final class HostedGateway extends Gateway
{
    public function description(): string
    {
        return match ($this->key) {
            'seylan' => 'Visa, Mastercard and Amex processed securely by Seylan Bank. You will be redirected to the bank.',
            'koko' => 'Split your purchase into 3 interest-free instalments with KOKO.',
            'payzy' => 'Pay instantly with your Payzy wallet.',
            'mintpay' => 'Buy now and pay later in easy instalments with Mintpay.',
            default => 'Secure online payment.',
        };
    }

    public function requiresRedirect(): bool
    {
        return true;
    }

    public function isAvailable(float $total): bool
    {
        if (!parent::isAvailable($total)) {
            return false;
        }
        $min = (float) ($this->config['min_total'] ?? 0);
        return $min <= 0 || $total >= $min;
    }

    private function endpoint(): string
    {
        $mode = (string) ($this->config['mode'] ?? 'sandbox');
        return $mode === 'live'
            ? (string) ($this->config['live_url'] ?? '')
            : (string) ($this->config['sandbox_url'] ?? '');
    }

    private function secret(): string
    {
        return (string) ($this->config['secret'] ?? '');
    }

    /**
     * Canonical payload sent to the provider.
     *
     * @return array<string,string>
     */
    public function requestPayload(array $order, array $payment): array
    {
        $amount = number_format((float) $order['total'], 2, '.', '');
        $payload = [
            'merchant_id' => (string) ($this->config['merchant_id'] ?? ''),
            'order_id'    => (string) $order['order_number'],
            'amount'      => $amount,
            'currency'    => (string) ($this->config['currency'] ?? 'LKR'),
            'description' => 'Order ' . $order['order_number'],
            'customer_name' => (string) $order['customer_name'],
            'customer_email' => (string) $order['email'],
            'customer_phone' => (string) $order['phone'],
            'return_url'  => $this->callbackUrl('/payment/return.php', ['gateway' => $this->key]),
            'notify_url'  => $this->callbackUrl('/payment/notify.php', ['gateway' => $this->key]),
            'cancel_url'  => $this->callbackUrl('/payment/return.php', ['gateway' => $this->key, 'cancelled' => 1]),
            'timestamp'   => (string) time(),
        ];

        // Providers with a nonce/request id are supported by including it in the
        // signed string; the signature covers every field except itself.
        $payload['request_id'] = (string) ($payment['gateway_reference'] ?? bin2hex(random_bytes(8)));
        $payload['signature'] = $this->sign($payload);
        return $payload;
    }

    /** @param array<string,string> $payload */
    public function sign(array $payload): string
    {
        $canonical = self::canonical($payload, ['signature']);
        return hash_hmac('sha256', $canonical, $this->secret());
    }

    /**
     * Callback signature check. Providers may post a different field order, so
     * we verify against their supplied signature field and fail closed.
     *
     * @param array<string,mixed> $payload
     */
    public function verifyCallback(array $payload): bool
    {
        $received = (string) ($payload['signature'] ?? $payload['sig'] ?? '');
        if ($received === '' || $this->secret() === '') {
            return false;
        }
        $stringPayload = [];
        foreach ($payload as $key => $value) {
            if (is_scalar($value)) {
                $stringPayload[(string) $key] = (string) $value;
            }
        }
        $expected = self::canonical($stringPayload, ['signature', 'sig']);
        return hash_equals(hash_hmac('sha256', $expected, $this->secret()), strtolower($received))
            || hash_equals(hash_hmac('sha256', $expected, $this->secret()), $received);
    }

    public function initiate(array $order, array $payment): array
    {
        if ($this->shouldSimulate()) {
            $token = Security::sign(['order' => (int) $order['id'], 'gw' => $this->key, 'sim' => 1], 3600);
            return [
                'type' => 'redirect',
                'url' => $this->callbackUrl('/payment/simulate.php', ['token' => $token]),
                'method' => 'GET',
                'params' => [],
                'simulated' => true,
            ];
        }

        $endpoint = $this->endpoint();
        if ($endpoint === '') {
            throw new RuntimeException('Payment gateway is not configured.');
        }

        return [
            'type' => 'redirect',
            'url' => $endpoint,
            'method' => 'POST',
            'params' => $this->requestPayload($order, $payment),
            'simulated' => false,
        ];
    }

    public function handleCallback(array $payload): array
    {
        $status = strtolower((string) ($payload['status'] ?? $payload['result'] ?? ''));
        $reference = (string) ($payload['transaction_id'] ?? $payload['reference'] ?? $payload['order_id'] ?? '');
        if (!$this->verifyCallback($payload)) {
            return ['status' => 'failed', 'reference' => $reference ?: null, 'message' => 'Payment signature could not be verified.'];
        }
        $paid = in_array($status, ['success', 'paid', 'completed', 'approved', 'captured'], true);
        $failed = in_array($status, ['failed', 'declined', 'cancelled', 'canceled', 'error'], true);
        if ($paid) {
            return ['status' => 'paid', 'reference' => $reference ?: null, 'message' => 'Payment confirmed by the gateway.'];
        }
        if ($failed) {
            return ['status' => 'failed', 'reference' => $reference ?: null, 'message' => 'The payment was not completed.'];
        }
        return ['status' => 'pending', 'reference' => $reference ?: null, 'message' => 'The gateway has not confirmed the payment yet.'];
    }

    /**
     * @param array<string,string> $payload
     * @param list<string> $exclude
     */
    public static function canonical(array $payload, array $exclude = []): string
    {
        foreach ($exclude as $skip) {
            unset($payload[$skip]);
        }
        ksort($payload);
        $pairs = [];
        foreach ($payload as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }
        return implode('&', $pairs);
    }
}

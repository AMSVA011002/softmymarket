<?php
declare(strict_types=1);

/**
 * PayHere hosted checkout integration.
 *
 * PayHere signs checkout fields with its MD5 merchant hash and signs notify
 * callbacks with `md5sig`. A browser return is never treated as proof of
 * payment: only a verified server-to-server notify can mark the order paid.
 */
final class PayHereGateway extends Gateway
{
    public function description(): string
    {
        return 'Pay securely with PayHere. You will be redirected to PayHere checkout.';
    }

    public function requiresRedirect(): bool
    {
        return true;
    }

    protected function shouldSimulate(): bool
    {
        return !empty($this->config['simulate'])
            || empty($this->config['merchant_id'])
            || empty($this->config['merchant_secret']);
    }

    private function secret(): string
    {
        return (string) ($this->config['merchant_secret'] ?? '');
    }

    private function endpoint(): string
    {
        $mode = (string) ($this->config['mode'] ?? 'sandbox');
        return $mode === 'live'
            ? (string) ($this->config['live_url'] ?? 'https://www.payhere.lk/pay/checkout')
            : (string) ($this->config['sandbox_url'] ?? 'https://sandbox.payhere.lk/pay/checkout');
    }

    /**
     * PayHere checkout hash: merchant id + order id + amount + currency +
     * uppercase MD5 of the merchant secret, all hashed once more as uppercase MD5.
     *
     * @param array<string,string> $payload
     */
    public function sign(array $payload): string
    {
        $merchantId = (string) ($payload['merchant_id'] ?? $this->config['merchant_id'] ?? '');
        $orderId = (string) ($payload['order_id'] ?? '');
        $amount = (string) ($payload['amount'] ?? '');
        $currency = (string) ($payload['currency'] ?? 'LKR');
        return strtoupper(md5($merchantId . $orderId . $amount . $currency . strtoupper(md5($this->secret()))));
    }

    /** @param array<string,mixed> $payload */
    public function verifyCallback(array $payload): bool
    {
        $merchantId = (string) ($this->config['merchant_id'] ?? '');
        $received = strtoupper(trim((string) ($payload['md5sig'] ?? '')));
        if ($merchantId === '' || $this->secret() === '' || $received === '') {
            return false;
        }
        if (!hash_equals($merchantId, (string) ($payload['merchant_id'] ?? ''))) {
            return false;
        }
        foreach (['order_id', 'payhere_amount', 'payhere_currency', 'status_code'] as $required) {
            if (!isset($payload[$required]) || !is_scalar($payload[$required])) {
                return false;
            }
        }
        $statusCode = (string) $payload['status_code'];
        if (!preg_match('/^-?\\d+$/', $statusCode)) {
            return false;
        }
        $expected = strtoupper(md5(
            $merchantId
            . (string) $payload['order_id']
            . (string) $payload['payhere_amount']
            . (string) $payload['payhere_currency']
            . $statusCode
            . strtoupper(md5($this->secret()))
        ));
        return hash_equals($expected, $received);
    }

    /** Ensure a validly signed callback is for this exact order and amount. */
    public function matchesOrder(array $payload, array $order): bool
    {
        if (!$this->verifyCallback($payload)) {
            return false;
        }
        if (strtoupper((string) ($order['payment_method'] ?? '')) !== 'PAYHERE') {
            return false;
        }
        $amount = number_format((float) $order['total'], 2, '.', '');
        $callbackAmount = str_replace([',', ' '], '', (string) ($payload['payhere_amount'] ?? '0'));
        return hash_equals((string) $order['order_number'], (string) ($payload['order_id'] ?? ''))
            && hash_equals($amount, number_format((float) $callbackAmount, 2, '.', ''))
            && hash_equals(strtoupper((string) $order['currency']), strtoupper((string) ($payload['payhere_currency'] ?? '')));
    }

    /** @return array{type:string,url?:string,method?:string,params?:array<string,string>,simulated?:bool} */
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

        $merchantId = (string) $this->config['merchant_id'];
        $orderId = (string) $order['order_number'];
        $amount = number_format((float) $order['total'], 2, '.', '');
        $currency = strtoupper((string) ($order['currency'] ?? $this->config['currency'] ?? 'LKR'));
        if (!in_array($currency, ['LKR', 'USD'], true)) {
            throw new RuntimeException('PayHere supports LKR or USD payments only.');
        }
        $shipping = (array) ($order['shipping_address_array'] ?? []);
        $fullName = trim((string) ($shipping['full_name'] ?? $order['customer_name'] ?? 'Customer'));
        $nameParts = preg_split('/\\s+/', $fullName, 2) ?: [];
        $firstName = (string) ($nameParts[0] ?? 'Customer');
        $lastName = (string) ($nameParts[1] ?? 'Customer');
        $address = trim(implode(', ', array_filter([
            (string) ($shipping['line1'] ?? ''),
            (string) ($shipping['line2'] ?? ''),
        ], static fn (string $part): bool => trim($part) !== '')));

        $returnToken = Security::sign(['order' => (int) $order['id'], 'gw' => $this->key], 60 * 60 * 24);
        $returnQuery = ['gateway' => $this->key, 'order' => $orderId, 'return_token' => $returnToken];
        $payload = [
            'merchant_id' => $merchantId,
            'return_url' => $this->callbackUrl('/payment/return.php', $returnQuery),
            'cancel_url' => $this->callbackUrl('/payment/return.php', $returnQuery + ['cancelled' => 1]),
            'notify_url' => $this->callbackUrl('/payment/notify.php', ['gateway' => $this->key]),
            'order_id' => $orderId,
            'items' => 'SoftMyMarket order ' . $orderId,
            'currency' => $currency,
            'amount' => $amount,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => (string) ($order['email'] ?? ''),
            'phone' => (string) ($shipping['phone'] ?? $order['phone'] ?? ''),
            'address' => $address !== '' ? $address : 'Sri Lanka',
            'city' => (string) ($shipping['city'] ?? ''),
            'country' => (string) ($shipping['country'] ?? 'Sri Lanka'),
            'delivery_address' => $address !== '' ? $address : 'Sri Lanka',
            'delivery_city' => (string) ($shipping['city'] ?? ''),
            'delivery_country' => (string) ($shipping['country'] ?? 'Sri Lanka'),
        ];
        $payload['hash'] = $this->sign($payload);

        return [
            'type' => 'redirect',
            'url' => $this->endpoint(),
            'method' => 'POST',
            'params' => $payload,
            'simulated' => false,
        ];
    }

    /** @param array<string,mixed> $payload @return array{status:string,reference:?string,message:string} */
    public function handleCallback(array $payload): array
    {
        $reference = (string) ($payload['payment_id'] ?? '');
        if (!$this->verifyCallback($payload)) {
            return ['status' => 'failed', 'reference' => $reference !== '' ? $reference : null, 'message' => 'PayHere signature could not be verified.'];
        }
        $statusCode = (int) $payload['status_code'];
        if ($statusCode === 2) {
            return ['status' => 'paid', 'reference' => $reference !== '' ? $reference : null, 'message' => 'PayHere confirmed the payment.'];
        }
        if (in_array($statusCode, [-1, -2, -3], true)) {
            return ['status' => 'failed', 'reference' => $reference !== '' ? $reference : null, 'message' => 'PayHere reported an unsuccessful payment.'];
        }
        return ['status' => 'pending', 'reference' => $reference !== '' ? $reference : null, 'message' => 'PayHere is still processing the payment.'];
    }
}

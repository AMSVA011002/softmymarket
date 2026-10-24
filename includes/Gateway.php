<?php
declare(strict_types=1);

/**
 * Base class for every payment method.
 *
 * A gateway knows three things:
 *   1. whether it can be shown for a given basket total (isAvailable)
 *   2. how to start a payment (initiate) — usually a signed redirect
 *   3. how to interpret a callback (handleCallback) — always verify the
 *      signature first; a callback is untrusted input until proven otherwise
 */
abstract class Gateway
{
    public string $key;
    /** @var array<string,mixed> */
    public array $config;

    public function __construct(string $key, array $config)
    {
        $this->key = $key;
        $this->config = $config;
    }

    public function label(): string
    {
        return payment_method_label(self::methodCode($this->key));
    }

    public function enabled(): bool
    {
        return !empty($this->config['enabled']);
    }

    /** Some methods have a minimum/maximum order value. */
    public function isAvailable(float $total): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        if (isset($this->config['min_total']) && $total < (float) $this->config['min_total']) {
            return false;
        }
        if (isset($this->config['max_total']) && (float) $this->config['max_total'] > 0 && $total > (float) $this->config['max_total']) {
            return false;
        }
        return true;
    }

    public function requiresRedirect(): bool
    {
        return false;
    }

    /** Short customer-facing explanation shown at checkout. */
    abstract public function description(): string;

    /**
     * Start the payment.
     *
     * @return array{type:string,url?:string,method?:string,params?:array<string,string>,message?:string}
     */
    abstract public function initiate(array $order, array $payment): array;

    /** @param array<string,mixed> $payload */
    abstract public function verifyCallback(array $payload): bool;

    /**
     * @param array<string,mixed> $payload
     * @return array{status:string,reference:?string,message:string}
     */
    abstract public function handleCallback(array $payload): array;

    /** Map a storefront method code to the gateway config key. */
    public static function methodCode(string $key): string
    {
        return match ($key) {
            'seylan' => 'CARD_SEYLAN',
            'bank_transfer' => 'BANK_TRANSFER',
            'koko' => 'KOKO',
            'payzy' => 'PAYZY',
            'mintpay' => 'MINTPAY',
            default => strtoupper($key),
        };
    }

    /** True when the gateway should show the internal simulation screen. */
    protected function shouldSimulate(): bool
    {
        if (!empty($this->config['simulate'])) {
            return true;
        }
        return empty($this->config['merchant_id']) || empty($this->config['secret']);
    }

    /** Base URL used by callbacks (always absolute and https in production). */
    protected function callbackUrl(string $path, array $query = []): string
    {
        return url($path, $query);
    }

    /** Stable, signed reference we store against the payment row. */
    protected function buildReference(array $order): string
    {
        return strtoupper($this->key) . '-' . $order['order_number'] . '-' . bin2hex(random_bytes(4));
    }
}

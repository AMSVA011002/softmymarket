<?php
declare(strict_types=1);

/**
 * Resolves the configured payment methods for a basket and builds gateways.
 */
final class PaymentManager
{
    /** @var array<string,Gateway>|null */
    private static ?array $gateways = null;

    /** @return array<string,Gateway> keyed by storefront method code (COD, CARD_SEYLAN…) */
    public static function gateways(): array
    {
        if (self::$gateways !== null) {
            return self::$gateways;
        }
        $config = (array) Config::get('payments', []);
        $map = [];
        $map['COD'] = new CodGateway('cod', (array) ($config['cod'] ?? []));
        $map['BANK_TRANSFER'] = new BankTransferGateway('bank_transfer', (array) ($config['bank_transfer'] ?? []));
        $map['CARD_SEYLAN'] = new HostedGateway('seylan', (array) ($config['seylan'] ?? []));
        $map['PAYHERE'] = new PayHereGateway('payhere', (array) ($config['payhere'] ?? []));
        $map['KOKO'] = new HostedGateway('koko', (array) ($config['koko'] ?? []));
        $map['PAYZY'] = new HostedGateway('payzy', (array) ($config['payzy'] ?? []));
        $map['MINTPAY'] = new HostedGateway('mintpay', (array) ($config['mintpay'] ?? []));
        self::$gateways = $map;
        return $map;
    }

    /** @return array<string,Gateway> */
    public static function available(float $total): array
    {
        $available = [];
        foreach (self::gateways() as $code => $gateway) {
            if ($gateway->isAvailable($total)) {
                $available[$code] = $gateway;
            }
        }
        return $available;
    }

    /**
     * Resolve a gateway from either a storefront method code (CARD_SEYLAN) or a
     * provider/config key (seylan). Callback URLs carry the config key, checkout
     * carries the method code — both must work.
     */
    public static function get(string $method): ?Gateway
    {
        $map = self::gateways();
        $code = strtoupper(trim($method));
        if (isset($map[$code])) {
            return $map[$code];
        }
        $key = strtolower(trim($method));
        foreach ($map as $gateway) {
            if ($gateway->key === $key) {
                return $gateway;
            }
        }
        return null;
    }

    public static function isEnabled(string $method): bool
    {
        $gateway = self::get($method);
        return $gateway !== null && $gateway->enabled();
    }

    /** Storefront method code (COD, CARD_SEYLAN…) for a gateway config key. */
    public static function methodCode(string $key): string
    {
        return match (strtolower($key)) {
            'seylan', 'card', 'card_seylan' => 'CARD_SEYLAN',
            'payhere' => 'PAYHERE',
            'bank', 'bank_transfer', 'transfer' => 'BANK_TRANSFER',
            'koko' => 'KOKO',
            'payzy' => 'PAYZY',
            'mintpay' => 'MINTPAY',
            'cod', 'cash' => 'COD',
            default => strtoupper($key),
        };
    }

    /** Gateway config key (lowercase) for a storefront method code. */
    public static function keyForMethod(string $method): string
    {
        return strtolower(match (strtoupper($method)) {
            'CARD_SEYLAN' => 'seylan',
            'PAYHERE' => 'payhere',
            'BANK_TRANSFER' => 'bank_transfer',
            default => strtoupper($method) === 'COD' ? 'cod' : strtolower($method),
        });
    }

    /** Records the initial payment row for an order. */
    public static function createInitialPayment(array $order): int
    {
        $method = (string) $order['payment_method'];
        $gateway = self::get($method);
        $key = self::keyForMethod($method);
        $reference = null;
        $status = 'pending';

        if ($method === 'COD') {
            $status = 'pending';
        } elseif ($method === 'BANK_TRANSFER') {
            $status = 'pending';
        } elseif ($gateway instanceof HostedGateway) {
            $reference = $gateway->requestPayload($order, ['gateway_reference' => null])['request_id'] ?? null;
        }

        return Payments::create(
            (int) $order['id'],
            $key,
            (float) $order['total'],
            (string) $order['currency'],
            $status,
            $reference,
            ['method' => $method]
        );
    }
}

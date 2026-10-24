<?php
declare(strict_types=1);

/**
 * Cash on delivery. Nothing to charge up-front: the rider collects the money.
 */
final class CodGateway extends Gateway
{
    public function description(): string
    {
        return 'Pay the rider in cash when your parcel arrives. Available island-wide.';
    }

    public function isAvailable(float $total): bool
    {
        if (!parent::isAvailable($total)) {
            return false;
        }
        $max = (float) ($this->config['max_total'] ?? 0);
        return $max <= 0 || $total <= $max;
    }

    public function initiate(array $order, array $payment): array
    {
        return ['type' => 'done', 'message' => 'Order placed — pay on delivery.'];
    }

    public function verifyCallback(array $payload): bool
    {
        return false;
    }

    public function handleCallback(array $payload): array
    {
        return ['status' => 'pending', 'reference' => null, 'message' => 'Cash on delivery needs no online confirmation.'];
    }
}

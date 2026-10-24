<?php
declare(strict_types=1);

/**
 * Manual bank transfer. The customer uploads the slip (or enters the reference)
 * from the order page; finance verifies it in the dashboard.
 */
final class BankTransferGateway extends Gateway
{
    public function description(): string
    {
        return 'Transfer to our Seylan Bank or Commercial Bank account, then upload the slip. Orders ship after verification.';
    }

    /** @return list<array<string,string>> */
    public function accounts(): array
    {
        return (array) ($this->config['accounts'] ?? []);
    }

    public function instructions(): string
    {
        return (string) ($this->config['instructions'] ?? '');
    }

    public function initiate(array $order, array $payment): array
    {
        $lines = [];
        foreach ($this->accounts() as $account) {
            $lines[] = $account['bank'] . ' — ' . $account['number'] . ' (' . $account['name'] . ')';
        }
        return [
            'type' => 'instructions',
            'message' => 'Transfer ' . money((float) $order['total']) . ' to: ' . implode(' | ', $lines) . '. Then upload your slip on the order page.',
        ];
    }

    public function verifyCallback(array $payload): bool
    {
        return false; // verified manually by a member of staff
    }

    public function handleCallback(array $payload): array
    {
        return ['status' => 'pending', 'reference' => null, 'message' => 'Awaiting manual verification.'];
    }
}

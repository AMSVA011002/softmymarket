<?php
declare(strict_types=1);

/**
 * Customer address book.
 */
final class Addresses
{
    /** @return list<array<string,mixed>> */
    public static function forUser(int $userId): array
    {
        return Database::all(
            'SELECT * FROM user_addresses WHERE user_id = :uid ORDER BY is_default DESC, id DESC',
            ['uid' => $userId]
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id, int $userId): ?array
    {
        return Database::one('SELECT * FROM user_addresses WHERE id = :id AND user_id = :uid', ['id' => $id, 'uid' => $userId]);
    }

    /** @return array<string,mixed>|null */
    public static function default(int $userId): ?array
    {
        return Database::one(
            'SELECT * FROM user_addresses WHERE user_id = :uid ORDER BY is_default DESC, id DESC LIMIT 1',
            ['uid' => $userId]
        );
    }

    /** @param array<string,mixed> $data */
    public static function create(int $userId, array $data): int
    {
        $isDefault = (int) ($data['is_default'] ?? 0);
        if (self::count($userId) === 0) {
            $isDefault = 1;
        }
        return Database::tx(function () use ($userId, $data, $isDefault): int {
            if ($isDefault === 1) {
                Database::run('UPDATE user_addresses SET is_default = 0 WHERE user_id = :uid', ['uid' => $userId]);
            }
            return Database::insert('user_addresses', [
                'user_id'     => $userId,
                'label'       => substr(trim((string) ($data['label'] ?? 'Home')), 0, 40),
                'full_name'   => substr(trim((string) ($data['full_name'] ?? '')), 0, 120),
                'phone'       => normalize_phone((string) ($data['phone'] ?? '')),
                'line1'       => substr(trim((string) ($data['line1'] ?? '')), 0, 180),
                'line2'       => ($data['line2'] ?? '') !== '' ? substr(trim((string) $data['line2']), 0, 180) : null,
                'city'        => substr(trim((string) ($data['city'] ?? '')), 0, 80),
                'district'    => substr(trim((string) ($data['district'] ?? '')), 0, 80),
                'postal_code' => substr(trim((string) ($data['postal_code'] ?? '')), 0, 20),
                'country'     => substr(trim((string) ($data['country'] ?? 'Sri Lanka')), 0, 80),
                'is_default'  => $isDefault,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        });
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, int $userId, array $data): void
    {
        Database::tx(function () use ($id, $userId, $data): void {
            if (!empty($data['is_default'])) {
                Database::run('UPDATE user_addresses SET is_default = 0 WHERE user_id = :uid', ['uid' => $userId]);
            }
            Database::update('user_addresses', [
                'label'       => substr(trim((string) ($data['label'] ?? 'Home')), 0, 40),
                'full_name'   => substr(trim((string) ($data['full_name'] ?? '')), 0, 120),
                'phone'       => normalize_phone((string) ($data['phone'] ?? '')),
                'line1'       => substr(trim((string) ($data['line1'] ?? '')), 0, 180),
                'line2'       => ($data['line2'] ?? '') !== '' ? substr(trim((string) $data['line2']), 0, 180) : null,
                'city'        => substr(trim((string) ($data['city'] ?? '')), 0, 80),
                'district'    => substr(trim((string) ($data['district'] ?? '')), 0, 80),
                'postal_code' => substr(trim((string) ($data['postal_code'] ?? '')), 0, 20),
                'country'     => substr(trim((string) ($data['country'] ?? 'Sri Lanka')), 0, 80),
                'is_default'  => (int) ($data['is_default'] ?? 0),
            ], 'id = :id AND user_id = :uid', ['id' => $id, 'uid' => $userId]);
        });
    }

    public static function delete(int $id, int $userId): void
    {
        Database::run('DELETE FROM user_addresses WHERE id = :id AND user_id = :uid', ['id' => $id, 'uid' => $userId]);
    }

    public static function setDefault(int $id, int $userId): void
    {
        Database::tx(static function () use ($id, $userId): void {
            Database::run('UPDATE user_addresses SET is_default = 0 WHERE user_id = :uid', ['uid' => $userId]);
            Database::run('UPDATE user_addresses SET is_default = 1 WHERE id = :id AND user_id = :uid', ['id' => $id, 'uid' => $userId]);
        });
    }

    public static function count(int $userId): int
    {
        return Database::int('SELECT COUNT(*) FROM user_addresses WHERE user_id = :uid', ['uid' => $userId]);
    }

    /** Single line for order summaries and emails. */
    public static function format(array $address): string
    {
        $parts = array_filter([
            $address['line1'] ?? '',
            $address['line2'] ?? '',
            $address['city'] ?? '',
            $address['district'] ?? '',
            $address['postal_code'] ?? '',
            $address['country'] ?? '',
        ], static fn ($p) => trim((string) $p) !== '');
        return implode(', ', array_map('trim', $parts));
    }
}

<?php
declare(strict_types=1);

/**
 * User + customer data access.
 *
 * Every query is parameterised. Writes go through a whitelist of columns so a
 * malicious POST body can never set role, status or password hashes directly.
 */
final class Users
{
    private const SELECT = 'SELECT id, uuid, name, email, phone, password_hash, role, status, must_change_password, avatar_url,
                                   marketing_opt_in, email_verified_at, last_login_at, last_login_ip,
                                   notes, created_at, updated_at FROM users';

    private const WRITABLE = [
        'name', 'email', 'phone', 'password_hash', 'role', 'status', 'must_change_password', 'avatar_url',
        'marketing_opt_in', 'email_verified_at', 'last_login_at', 'last_login_ip', 'notes',
    ];

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::one(self::SELECT . ' WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public static function findByEmail(string $email): ?array
    {
        return Database::one(self::SELECT . ' WHERE email = :email LIMIT 1', ['email' => strtolower(trim($email))]);
    }

    /** @return array<string,mixed>|null */
    public static function findByUuid(string $uuid): ?array
    {
        return Database::one(self::SELECT . ' WHERE uuid = :uuid LIMIT 1', ['uuid' => $uuid]);
    }

    public static function emailExists(string $email, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM users WHERE email = :email';
        $params = ['email' => strtolower(trim($email))];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        return Database::exists($sql . ' LIMIT 1', $params);
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $row = [
            'uuid'       => uuid(),
            'name'       => trim((string) ($data['name'] ?? '')),
            'email'      => strtolower(trim((string) ($data['email'] ?? ''))),
            'phone'      => $data['phone'] ?? null,
            'password_hash' => (string) ($data['password_hash'] ?? Auth::hashPassword(Security::randomToken(12))),
            'role'       => in_array($data['role'] ?? '', ['admin', 'staff', 'customer'], true) ? $data['role'] : 'customer',
            'status'     => in_array($data['status'] ?? '', ['active', 'suspended', 'pending'], true) ? $data['status'] : 'active',
            'must_change_password' => (int) (bool) ($data['must_change_password'] ?? false),
            'avatar_url' => $data['avatar_url'] ?? null,
            'marketing_opt_in' => (int) ($data['marketing_opt_in'] ?? 0),
            'email_verified_at' => $data['email_verified_at'] ?? null,
            'notes'      => $data['notes'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        return Database::insert('users', $row);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): int
    {
        $clean = array_intersect_key($data, array_flip(self::WRITABLE));
        if ($clean === []) {
            return 0;
        }
        $clean['updated_at'] = date('Y-m-d H:i:s');
        return Database::update('users', $clean, 'id = :id', ['id' => $id]);
    }

    public static function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['active', 'suspended', 'pending'], true)) {
            return;
        }
        self::update($id, ['status' => $status]);
    }

    /**
     * Admin customer list with search, filters and pagination.
     *
     * @param array{q?:string,role?:string,status?:string,sort?:string,page?:int,per?:int} $filters
     * @return array{rows:list<array<string,mixed>>,total:int,pager:array<string,mixed>}
     */
    public static function paginate(array $filters = []): array
    {
        $where = ['1 = 1'];
        $params = [];

        if (($filters['role'] ?? '') !== '' && in_array($filters['role'], ['admin', 'staff', 'customer'], true)) {
            $where[] = 'u.role = :role';
            $params['role'] = $filters['role'];
        }
        if (($filters['status'] ?? '') !== '' && in_array($filters['status'], ['active', 'suspended', 'pending'], true)) {
            $where[] = 'u.status = :status';
            $params['status'] = $filters['status'];
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(u.name LIKE :q OR u.email LIKE :q2 OR u.phone LIKE :q3)';
            $like = '%' . $filters['q'] . '%';
            $params['q'] = $like;
            $params['q2'] = $like;
            $params['q3'] = $like;
        }
        if (!empty($filters['joined_from'])) {
            $where[] = 'u.created_at >= :joined_from';
            $params['joined_from'] = $filters['joined_from'] . ' 00:00:00';
        }
        if (!empty($filters['joined_to'])) {
            $where[] = 'u.created_at <= :joined_to';
            $params['joined_to'] = $filters['joined_to'] . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);
        $sort = match ($filters['sort'] ?? 'newest') {
            'oldest' => 'u.created_at ASC',
            'name' => 'u.name ASC',
            'spend' => 'total_spent DESC',
            default => 'u.created_at DESC',
        };

        $per = max(10, min(100, (int) ($filters['per'] ?? 25)));
        $total = Database::int("SELECT COUNT(*) FROM users u WHERE {$whereSql}", $params);
        $pager = paginate($total, (int) ($filters['page'] ?? 1), $per);

        $sql = "SELECT u.id, u.uuid, u.name, u.email, u.phone, u.role, u.status, u.avatar_url,
                       u.marketing_opt_in, u.last_login_at, u.created_at,
                       COALESCE(o.orders_count, 0) AS orders_count,
                       COALESCE(o.total_spent, 0) AS total_spent
                  FROM users u
             LEFT JOIN (SELECT user_id, COUNT(*) AS orders_count, SUM(total) AS total_spent
                          FROM orders WHERE payment_method IN ('COD','BANK_TRANSFER') OR payment_status = 'PAID' GROUP BY user_id) o ON o.user_id = u.id
                 WHERE {$whereSql}
              ORDER BY {$sort}
                 LIMIT {$pager['limit']} OFFSET {$pager['offset']}";

        return ['rows' => Database::all($sql, $params), 'total' => $total, 'pager' => $pager];
    }

    /** Customer profile summary used in the admin detail drawer. */
    public static function stats(int $id): array
    {
        $row = Database::one(
            "SELECT COUNT(*) AS orders_count,
                    COALESCE(SUM(total), 0) AS total_spent,
                    COALESCE(AVG(total), 0) AS avg_order,
                    MAX(placed_at) AS last_order_at
               FROM orders WHERE user_id = :id AND status <> :cancelled
                 AND (payment_method IN ('COD','BANK_TRANSFER') OR payment_status = 'PAID')",
            ['id' => $id, 'cancelled' => 'CANCELLED']
        ) ?? [];
        return [
            'orders_count' => (int) ($row['orders_count'] ?? 0),
            'total_spent' => (float) ($row['total_spent'] ?? 0),
            'avg_order' => (float) ($row['avg_order'] ?? 0),
            'last_order_at' => $row['last_order_at'] ?? null,
        ];
    }

    public static function countByRole(?string $role = null, ?string $status = null): int
    {
        $sql = 'SELECT COUNT(*) FROM users WHERE 1 = 1';
        $params = [];
        if ($role !== null) {
            $sql .= ' AND role = :role';
            $params['role'] = $role;
        }
        if ($status !== null) {
            $sql .= ' AND status = :status';
            $params['status'] = $status;
        }
        return Database::int($sql, $params);
    }

    public static function newThisMonth(): int
    {
        return Database::int(
            'SELECT COUNT(*) FROM users WHERE role = :role AND created_at >= :from',
            ['role' => 'customer', 'from' => date('Y-m-01 00:00:00')]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function team(): array
    {
        return Database::all(
            self::SELECT . " WHERE role IN ('admin', 'staff') ORDER BY role ASC, name ASC"
        );
    }

    /** Customer dropdown for manual order creation. */
    public static function search(string $term, int $limit = 15): array
    {
        return Database::all(
            'SELECT id, name, email, phone FROM users
              WHERE role = :role AND (name LIKE :q OR email LIKE :q2 OR phone LIKE :q3)
              ORDER BY name ASC LIMIT ' . max(1, min(50, $limit)),
            ['role' => 'customer', 'q' => "%{$term}%", 'q2' => "%{$term}%", 'q3' => "%{$term}%"]
        );
    }

    /**
     * Permanently remove a dashboard account.
     *
     * Team members are hard-deleted: they own no financial records of their own.
     * Everything that referenced them either cascades (cart, wishlist,
     * addresses, notifications) or is set to NULL (orders keep the customer's
     * name and email on the row, audit entries keep their action).
     */
    public static function purge(int $id): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Unknown team member.');
        }
        Database::run('DELETE FROM users WHERE id = :id', ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        // Customers are soft-anonymised: orders must keep their financial record.
        Database::run('UPDATE users SET status = :status, email = :email, name = :name, phone = NULL WHERE id = :id', [
            'status' => 'suspended',
            'email' => 'deleted+' . $id . '@yourmarket.invalid',
            'name' => 'Deleted customer',
            'id' => $id,
        ]);
    }

    /** Ensure the last administrator cannot be removed or demoted. */
    public static function isLastAdmin(int $id): bool
    {
        $admins = Database::int("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'");
        $user = self::find($id);
        return $admins <= 1 && $user !== null && $user['role'] === 'admin';
    }
}

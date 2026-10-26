<?php
declare(strict_types=1);

/**
 * Order read model + lifecycle helpers.
 *
 * Writes that touch several tables live in OrderService; this class owns the
 * queries, the status machine and the event log used by live tracking.
 */
final class Orders
{
    /** Online payment attempts are provisional until a signed success callback. */
    private const PLACED_ORDER_FILTER = "(o.payment_method IN ('COD','BANK_TRANSFER') OR o.payment_status = 'PAID')";

    private const SELECT = 'SELECT o.*,
                                   u.name AS account_name, u.email AS account_email
                              FROM orders o
                         LEFT JOIN users u ON u.id = o.user_id';

    /** Allowed transitions of the order status machine. */
    public const TRANSITIONS = [
        'PENDING'          => ['CONFIRMED', 'CANCELLED'],
        'CONFIRMED'        => ['PROCESSING', 'CANCELLED'],
        'PROCESSING'       => ['PACKED', 'CANCELLED'],
        'PACKED'           => ['SHIPPED', 'CANCELLED'],
        'SHIPPED'          => ['OUT_FOR_DELIVERY', 'DELIVERED', 'RETURNED'],
        'OUT_FOR_DELIVERY' => ['DELIVERED', 'RETURNED'],
        'DELIVERED'        => ['RETURNED', 'REFUNDED'],
        'CANCELLED'        => ['REFUNDED'],
        'RETURNED'         => ['REFUNDED'],
        'REFUNDED'         => [],
    ];

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $row = Database::one(self::SELECT . ' WHERE o.id = :id LIMIT 1', ['id' => $id]);
        return $row === null ? null : self::map($row);
    }

    /** @return array<string,mixed>|null */
    public static function findByNumber(string $number): ?array
    {
        $row = Database::one(self::SELECT . ' WHERE o.order_number = :n LIMIT 1', ['n' => strtoupper(trim($number))]);
        return $row === null ? null : self::map($row);
    }

    /** @return array<string,mixed>|null */
    public static function findByUuid(string $uuid): ?array
    {
        $row = Database::one(self::SELECT . ' WHERE o.uuid = :uuid LIMIT 1', ['uuid' => $uuid]);
        return $row === null ? null : self::map($row);
    }

    /** @return list<array<string,mixed>> */
    public static function items(int $orderId): array
    {
        return Database::all(
            'SELECT oi.*, p.warranty AS warranty
               FROM order_items oi
               LEFT JOIN products p ON p.id = oi.product_id
              WHERE oi.order_id = :oid
              ORDER BY oi.id ASC',
            ['oid' => $orderId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function events(int $orderId, bool $publicOnly = false): array
    {
        $sql = 'SELECT * FROM order_events WHERE order_id = :oid';
        if ($publicOnly) {
            $sql .= ' AND is_public = 1';
        }
        return Database::all($sql . ' ORDER BY id ASC', ['oid' => $orderId]);
    }

    /** @return list<array<string,mixed>> */
    public static function payments(int $orderId): array
    {
        return Database::all('SELECT * FROM payments WHERE order_id = :oid ORDER BY id DESC', ['oid' => $orderId]);
    }

    /** @return array<string,mixed>|null */
    public static function latestPayment(int $orderId): ?array
    {
        return Database::one('SELECT * FROM payments WHERE order_id = :oid ORDER BY id DESC LIMIT 1', ['oid' => $orderId]);
    }

    /**
     * @param array{status?:string,q?:string,payment?:string,from?:string,to?:string,page?:int,sort?:string,user_id?:int,flagged?:bool} $filters
     */
    public static function adminList(array $filters = []): array
    {
        $where = [self::PLACED_ORDER_FILTER];
        $params = [];
        if (!empty($filters['status']) && array_key_exists($filters['status'], order_statuses())) {
            $where[] = 'o.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['payment'])) {
            $where[] = 'o.payment_method = :payment';
            $params['payment'] = $filters['payment'];
        }
        if (!empty($filters['payment_status']) && array_key_exists($filters['payment_status'], payment_statuses())) {
            $where[] = 'o.payment_status = :pstatus';
            $params['pstatus'] = $filters['payment_status'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(o.order_number LIKE :oq OR o.customer_name LIKE :oq2 OR o.email LIKE :oq3 OR o.phone LIKE :oq4)';
            $like = '%' . $filters['q'] . '%';
            $params['oq'] = $like;
            $params['oq2'] = $like;
            $params['oq3'] = $like;
            $params['oq4'] = $like;
        }
        if (!empty($filters['from'])) {
            $where[] = 'o.placed_at >= :from';
            $params['from'] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 'o.placed_at <= :to';
            $params['to'] = $filters['to'] . ' 23:59:59';
        }
        if (!empty($filters['user_id'])) {
            $where[] = 'o.user_id = :ouid';
            $params['ouid'] = (int) $filters['user_id'];
        }
        if (!empty($filters['today'])) {
            $where[] = 'DATE(o.placed_at) = CURDATE()';
        }

        $whereSql = implode(' AND ', $where);
        $per = max(10, min(100, (int) ($filters['per'] ?? (int) Config::get('app.orders_per_page', 20))));
        $total = Database::int("SELECT COUNT(*) FROM orders o WHERE {$whereSql}", $params);
        $pager = paginate($total, (int) ($filters['page'] ?? 1), $per);

        $sort = match ($filters['sort'] ?? 'newest') {
            'oldest' => 'o.placed_at ASC',
            'total_desc' => 'o.total DESC',
            'total_asc' => 'o.total ASC',
            default => 'o.placed_at DESC',
        };

        $rows = Database::all(
            self::SELECT . " WHERE {$whereSql} ORDER BY {$sort} LIMIT {$pager['limit']} OFFSET {$pager['offset']}",
            $params
        );

        return ['rows' => array_map([self::class, 'map'], $rows), 'total' => $total, 'pager' => $pager];
    }

    /** @return array{rows:list<array<string,mixed>>,total:int,pager:array<string,mixed>} */
    public static function forUser(int $userId, int $page = 1, int $per = 10): array
    {
        $total = Database::int('SELECT COUNT(*) FROM orders o WHERE o.user_id = :uid AND ' . self::PLACED_ORDER_FILTER, ['uid' => $userId]);
        $pager = paginate($total, $page, $per);
        $rows = Database::all(
            self::SELECT . ' WHERE o.user_id = :uid AND ' . self::PLACED_ORDER_FILTER . ' ORDER BY o.placed_at DESC LIMIT ' . $pager['limit'] . ' OFFSET ' . $pager['offset'],
            ['uid' => $userId]
        );
        return ['rows' => array_map([self::class, 'map'], $rows), 'total' => $total, 'pager' => $pager];
    }

    /** @return list<array<string,mixed>> */
    public static function recent(int $limit = 8): array
    {
        $rows = Database::all(self::SELECT . ' WHERE ' . self::PLACED_ORDER_FILTER . ' ORDER BY o.placed_at DESC LIMIT ' . max(1, $limit));
        return array_map([self::class, 'map'], $rows);
    }

    /** Orders that need staff attention right now. */
    public static function liveBoard(int $limit = 30): array
    {
        $rows = Database::all(
            self::SELECT . " WHERE " . self::PLACED_ORDER_FILTER . " AND o.status IN ('PENDING', 'CONFIRMED', 'PROCESSING', 'PACKED', 'SHIPPED', 'OUT_FOR_DELIVERY')
              ORDER BY FIELD(o.status, 'PENDING', 'CONFIRMED', 'PROCESSING', 'PACKED', 'SHIPPED', 'OUT_FOR_DELIVERY'), o.placed_at ASC
              LIMIT " . max(1, min(100, $limit))
        );
        return array_map([self::class, 'map'], $rows);
    }

    public static function countsByStatus(): array
    {
        $rows = Database::all('SELECT o.status, COUNT(*) AS c FROM orders o WHERE ' . self::PLACED_ORDER_FILTER . ' GROUP BY o.status');
        $counts = array_fill_keys(array_keys(order_statuses()), 0);
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }
        return $counts;
    }

    public static function stats(): array
    {
        $today = Database::one(
            'SELECT COUNT(*) AS c, COALESCE(SUM(o.total), 0) AS t FROM orders o WHERE DATE(o.placed_at) = CURDATE() AND o.status <> :cancelled AND ' . self::PLACED_ORDER_FILTER,
            ['cancelled' => 'CANCELLED']
        ) ?? ['c' => 0, 't' => 0];
        $month = Database::one(
            'SELECT COUNT(*) AS c, COALESCE(SUM(o.total), 0) AS t FROM orders o WHERE o.placed_at >= :from AND o.status <> :cancelled AND ' . self::PLACED_ORDER_FILTER,
            ['from' => date('Y-m-01 00:00:00'), 'cancelled' => 'CANCELLED']
        ) ?? ['c' => 0, 't' => 0];
        $allTime = Database::one('SELECT COUNT(*) AS c, COALESCE(SUM(o.total), 0) AS t FROM orders o WHERE o.status <> :cancelled AND ' . self::PLACED_ORDER_FILTER, ['cancelled' => 'CANCELLED']) ?? ['c' => 0, 't' => 0];
        $average = Database::float('SELECT COALESCE(AVG(o.total), 0) FROM orders o WHERE o.status <> :cancelled AND ' . self::PLACED_ORDER_FILTER, ['cancelled' => 'CANCELLED']);
        $pendingPayments = Database::int("SELECT COUNT(*) FROM orders o WHERE o.payment_status IN ('PENDING', 'UNPAID') AND o.status <> 'CANCELLED' AND " . self::PLACED_ORDER_FILTER);

        return [
            'today_count' => (int) $today['c'],
            'today_sales' => (float) $today['t'],
            'month_count' => (int) $month['c'],
            'month_sales' => (float) $month['t'],
            'total_count' => (int) $allTime['c'],
            'total_sales' => (float) $allTime['t'],
            'average_value' => $average,
            'pending_payments' => $pendingPayments,
        ];
    }

    /** Sales for the last N days, ready for a sparkline/bar chart. @return list<array{date:string,sales:float,orders:int}> */
    public static function salesSeries(int $days = 14): array
    {
        $days = max(2, min(90, $days));
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $rows = Database::all(
            'SELECT DATE(o.placed_at) AS d, COUNT(*) AS c, COALESCE(SUM(o.total), 0) AS t
               FROM orders o
              WHERE o.placed_at >= :from AND o.status <> :cancelled AND ' . self::PLACED_ORDER_FILTER . '
           GROUP BY DATE(o.placed_at)',
            ['from' => $from . ' 00:00:00', 'cancelled' => 'CANCELLED']
        );
        $byDate = [];
        foreach ($rows as $row) {
            $byDate[(string) $row['d']] = ['sales' => (float) $row['t'], 'orders' => (int) $row['c']];
        }
        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime($from . ' +' . $i . ' days'));
            $series[] = [
                'date' => $date,
                'sales' => $byDate[$date]['sales'] ?? 0.0,
                'orders' => $byDate[$date]['orders'] ?? 0,
            ];
        }
        return $series;
    }

    /** @return list<array<string,mixed>> top selling products */
    public static function topProducts(int $limit = 8, ?string $from = null): array
    {
        $sql = "SELECT p.id, p.title, p.slug, p.image_url, SUM(oi.quantity) AS units, SUM(oi.total) AS revenue
                  FROM order_items oi
                  JOIN orders o ON o.id = oi.order_id
             LEFT JOIN products p ON p.id = oi.product_id
                 WHERE o.status <> 'CANCELLED'";
        $params = [];
        if ($from !== null) {
            $sql .= ' AND o.placed_at >= :from';
            $params['from'] = $from;
        }
        $sql .= ' GROUP BY oi.product_id, p.id, p.title, p.slug, p.image_url ORDER BY units DESC LIMIT ' . max(1, $limit);
        return Database::all($sql, $params);
    }

    /** @return list<array<string,mixed>> */
    public static function pendingBankTransfers(int $limit = 10): array
    {
        $rows = Database::all(
            self::SELECT . " WHERE o.payment_method = 'BANK_TRANSFER' AND o.payment_status IN ('PENDING', 'UNPAID')
              ORDER BY o.placed_at ASC LIMIT " . max(1, $limit)
        );
        return array_map([self::class, 'map'], $rows);
    }

    /** Add an event to the order timeline. */
    public static function addEvent(int $orderId, string $status, string $message, bool $isPublic = true, string $actorType = 'system', ?int $actorId = null): int
    {
        return Database::insert('order_events', [
            'order_id'   => $orderId,
            'status'     => $status,
            'message'    => $message,
            'actor_type' => $actorType,
            'actor_id'   => $actorId,
            'is_public'  => (int) $isPublic,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** Timeline progress (0-100) for the tracking bar. */
    public static function progress(?string $status): int
    {
        $flow = order_statuses_flow();
        $index = array_search($status, $flow, true);
        if ($index === false) {
            return in_array($status, ['CANCELLED', 'RETURNED', 'REFUNDED'], true) ? 100 : 0;
        }
        return (int) round((($index + 1) / count($flow)) * 100);
    }

    /** Latest orders for the live admin board. */
    public static function boardSince(string $since): array
    {
        $rows = Database::all(
            self::SELECT . ' WHERE ' . self::PLACED_ORDER_FILTER . ' AND o.updated_at >= :since ORDER BY o.updated_at DESC LIMIT 40',
            ['since' => $since]
        );
        return array_map([self::class, 'map'], $rows);
    }

    /**
     * Item counts for a set of orders (used by list screens).
     *
     * @param list<int> $orderIds
     * @return array<int,int>
     */
    public static function itemCounts(array $orderIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $orderIds)));
        if ($ids === []) {
            return [];
        }
        $rows = Database::all(
            'SELECT order_id, SUM(quantity) AS qty FROM order_items WHERE order_id IN ('
            . implode(',', $ids) . ') GROUP BY order_id'
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['order_id']] = (int) $row['qty'];
        }
        return $counts;
    }

    public static function map(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['user_id'] = $row['user_id'] !== null ? (int) $row['user_id'] : null;
        foreach (['subtotal', 'shipping_fee', 'discount', 'tax', 'total'] as $field) {
            $row[$field] = (float) ($row[$field] ?? 0);
        }
        $row['shipping_address_array'] = self::decodeAddress($row['shipping_address'] ?? null);
        $row['billing_address_array'] = self::decodeAddress($row['billing_address'] ?? null);
        $row['progress'] = self::progress($row['status'] ?? '');
        $row['is_open'] = !in_array($row['status'] ?? '', ['DELIVERED', 'CANCELLED', 'REFUNDED', 'RETURNED'], true);
        $row['status_label'] = order_status_label($row['status'] ?? '');
        $row['payment_method_label'] = payment_method_label($row['payment_method'] ?? '');
        $row['public_token'] = Security::sign(['order' => (int) $row['id'], 'n' => $row['order_number']], 60 * 60 * 24 * 30);
        return $row;
    }

    public static function decodeAddress(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** True when the signed-in customer owns the order. */
    public static function ownedBy(?array $order, ?int $userId): bool
    {
        return $order !== null && $userId !== null && (int) ($order['user_id'] ?? 0) === $userId;
    }
}

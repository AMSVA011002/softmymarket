<?php
declare(strict_types=1);

/**
 * Search helpers beyond the storefront catalogue query.
 *
 * Everything here is parameter-bound: the only values interpolated into SQL are
 * sort keys chosen from a fixed whitelist and integers clamped with max().
 */
final class Search
{
    /** Global "jump to" search used by the dashboard header and command palette. */
    public static function global(string $term, int $limit = 6): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return ['products' => [], 'orders' => [], 'customers' => [], 'categories' => [], 'brands' => []];
        }
        $like = '%' . $term . '%';
        $limit = max(1, min(20, $limit));

        $products = Database::all(
            'SELECT id, title, slug, sku, price, stock, image_url, status
               FROM products
              WHERE deleted_at IS NULL
                AND (title LIKE :t1 OR sku LIKE :t2 OR tags LIKE :t3)
           ORDER BY (title LIKE :exact) DESC, sold_count DESC
              LIMIT ' . $limit,
            ['t1' => $like, 't2' => $like, 't3' => $like, 'exact' => $term . '%']
        );

        $orders = Database::all(
            "SELECT o.id, o.order_number, o.customer_name, o.total, o.status, o.placed_at
               FROM orders o
              WHERE (o.payment_method IN ('COD','BANK_TRANSFER') OR o.payment_status = 'PAID')
                AND (o.order_number LIKE :o1 OR o.customer_name LIKE :o2 OR o.email LIKE :o3 OR o.phone LIKE :o4)
           ORDER BY o.placed_at DESC
              LIMIT " . $limit,
            ['o1' => $like, 'o2' => $like, 'o3' => $like, 'o4' => $like]
        );

        $customers = Database::all(
            "SELECT id, name, email, phone, role, status, created_at
               FROM users
              WHERE name LIKE :u1 OR email LIKE :u2 OR phone LIKE :u3
           ORDER BY created_at DESC
              LIMIT " . $limit,
            ['u1' => $like, 'u2' => $like, 'u3' => $like]
        );

        $categories = Database::all(
            'SELECT id, name, slug, path FROM categories WHERE name LIKE :c ORDER BY position ASC LIMIT ' . $limit,
            ['c' => $like]
        );

        $brands = Database::all(
            'SELECT id, name, slug FROM brands WHERE name LIKE :b ORDER BY name ASC LIMIT ' . $limit,
            ['b' => $like]
        );

        return [
            'products' => $products,
            'orders' => $orders,
            'customers' => $customers,
            'categories' => $categories,
            'brands' => $brands,
        ];
    }

    /**
     * Inventory-oriented product search for the dashboard.
     *
     * @param array{q?:string,status?:string,stock?:string,category?:int,brand?:int,page?:int,per?:int,sort?:string} $filters
     */
    public static function adminProducts(array $filters = []): array
    {
        $where = ['p.deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['q'])) {
            $where[] = '(p.title LIKE :q1 OR p.sku LIKE :q2 OR p.tags LIKE :q3)';
            $like = '%' . $filters['q'] . '%';
            $params['q1'] = $like;
            $params['q2'] = $like;
            $params['q3'] = $like;
        }
        if (!empty($filters['status']) && in_array($filters['status'], ['active', 'draft', 'archived'], true)) {
            $where[] = 'p.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['category'])) {
            $category = Catalog::categoryById((int) $filters['category']);
            if ($category !== null) {
                $ids = Catalog::categoryDescendantIds($category);
                $where[] = 'p.category_id IN (' . implode(',', array_map('intval', $ids ?: [(int) $category['id']])) . ')';
            }
        }
        if (!empty($filters['brand'])) {
            $where[] = 'p.brand_id = :brand';
            $params['brand'] = (int) $filters['brand'];
        }
        switch ($filters['stock'] ?? '') {
            case 'low':
                $where[] = 'p.stock > 0 AND p.stock <= p.low_stock_alert';
                break;
            case 'out':
                $where[] = 'p.stock <= 0';
                break;
            case 'in':
                $where[] = 'p.stock > COALESCE(p.low_stock_alert, 0)';
                break;
        }

        $sort = match ($filters['sort'] ?? 'newest') {
            'oldest' => 'p.created_at ASC',
            'title' => 'p.title ASC',
            'price_asc' => 'p.price ASC',
            'price_desc' => 'p.price DESC',
            'stock_asc' => 'p.stock ASC',
            'sold' => 'p.sold_count DESC',
            default => 'p.created_at DESC',
        };

        $whereSql = implode(' AND ', $where);
        $total = Database::int("SELECT COUNT(*) FROM products p WHERE {$whereSql}", $params);
        $pager = paginate($total, (int) ($filters['page'] ?? 1), max(10, min(100, (int) ($filters['per'] ?? 25))));

        $rows = Database::all(
            "SELECT p.*, c.name AS category_name, b.name AS brand_name
               FROM products p
          LEFT JOIN categories c ON c.id = p.category_id
          LEFT JOIN brands b ON b.id = p.brand_id
              WHERE {$whereSql}
           ORDER BY {$sort}
              LIMIT {$pager['limit']} OFFSET {$pager['offset']}",
            $params
        );

        return ['rows' => $rows, 'pager' => $pager];
    }

    /**
     * Ranked storefront search with a relevance score.
     *
     * @return list<array<string,mixed>>
     */
    public static function storefront(string $term, int $limit = 24, int $offset = 0): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        $like = '%' . $term . '%';
        return Database::all(
            "SELECT p.*,
                    (CASE WHEN p.title = :exact THEN 100 ELSE 0 END) +
                    (CASE WHEN p.title LIKE :prefix THEN 40 ELSE 0 END) +
                    (CASE WHEN p.title LIKE :any THEN 20 ELSE 0 END) +
                    (CASE WHEN p.sku = :sku THEN 30 ELSE 0 END) +
                    (CASE WHEN p.tags LIKE :tags THEN 10 ELSE 0 END) +
                    LEAST(p.sold_count / 100, 10) AS score
               FROM products p
              WHERE p.status = 'active' AND p.deleted_at IS NULL
                AND (p.title LIKE :any2 OR p.sku LIKE :sku2 OR p.tags LIKE :tags2 OR p.short_description LIKE :desc)
           ORDER BY score DESC, p.sold_count DESC
              LIMIT " . max(1, min(60, $limit)) . ' OFFSET ' . max(0, $offset),
            [
                'exact' => $term,
                'prefix' => $term . '%',
                'any' => $like,
                'sku' => $term,
                'tags' => $like,
                'any2' => $like,
                'sku2' => $like,
                'tags2' => $like,
                'desc' => $like,
            ]
        );
    }

    /** Log a storefront search so the dashboard can show what people want. */
    public static function log(string $term, int $results, ?int $userId = null): void
    {
        $term = mb_substr(trim($term), 0, 120);
        if ($term === '') {
            return;
        }
        try {
            Database::insert('search_log', [
                'term' => $term,
                'results' => max(0, $results),
                'user_id' => $userId,
                'ip_address' => Security::clientIp(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            // Search must never fail because logging failed.
            error_log('search log failed: ' . $e->getMessage());
        }
    }

    /** Most searched terms (last 30 days). */
    public static function trendingTerms(int $limit = 10): array
    {
        return Database::all(
            "SELECT term, COUNT(*) AS hits, MAX(results) AS results
               FROM search_log
              WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
           GROUP BY term
           ORDER BY hits DESC
              LIMIT " . max(1, min(50, $limit))
        );
    }

    /** Searches that returned nothing — useful for buying decisions. */
    public static function emptyTerms(int $limit = 10): array
    {
        return Database::all(
            "SELECT term, COUNT(*) AS hits
               FROM search_log
              WHERE results = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
           GROUP BY term
           ORDER BY hits DESC
              LIMIT " . max(1, min(50, $limit))
        );
    }

    /** Recently placed orders matching a customer, for the order desk. */
    public static function customerOrders(int $userId, int $limit = 5): array
    {
        return Database::all(
            "SELECT id, order_number, total, status, payment_status, placed_at
               FROM orders WHERE user_id = :uid AND (payment_method IN ('COD','BANK_TRANSFER') OR payment_status = 'PAID')
           ORDER BY placed_at DESC LIMIT " . max(1, min(50, $limit)),
            ['uid' => $userId]
        );
    }
}

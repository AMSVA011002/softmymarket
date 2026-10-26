<?php
declare(strict_types=1);

/**
 * Catalogue read/write layer: categories, brands, products, reviews.
 *
 * Products always come back through mapProduct() so every template sees the
 * same shape (ints are ints, gallery is an array, discount is pre-computed).
 */
final class Catalog
{
    private const PRODUCT_SELECT = 'SELECT p.*,
                                           c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon,
                                           b.name AS brand_name, b.slug AS brand_slug
                                      FROM products p
                                 LEFT JOIN categories c ON c.id = p.category_id
                                 LEFT JOIN brands b ON b.id = p.brand_id';

    /* =====================================================================
       Categories
       ===================================================================== */

    /** @return list<array<string,mixed>> flat list ordered for navigation */
    public static function categories(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM categories';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $rows = Database::all($sql . ' ORDER BY position ASC, name ASC');
        return array_map([self::class, 'mapCategory'], $rows);
    }

    /** @return array<int,array<string,mixed>> keyed by id */
    public static function categoryMap(bool $activeOnly = false): array
    {
        $map = [];
        foreach (self::categories($activeOnly) as $category) {
            $map[(int) $category['id']] = $category;
        }
        return $map;
    }

    /** @return array<int,list<array<string,mixed>>> children keyed by parent id */
    private static function childrenMap(bool $activeOnly): array
    {
        $byParent = [];
        foreach (self::categories($activeOnly) as $category) {
            $parent = $category['parent_id'] !== null ? (int) $category['parent_id'] : 0;
            $byParent[$parent][] = $category;
        }
        return $byParent;
    }

    /** @return list<array<string,mixed>> nested tree (each node has a `children` list) */
    public static function categoryTree(bool $activeOnly = false, ?int $parentId = null, int $maxDepth = 12): array
    {
        $byParent = self::childrenMap($activeOnly);
        $build = static function (int $parent, int $depth) use (&$build, $byParent, $maxDepth): array {
            $nodes = [];
            foreach ($byParent[$parent] ?? [] as $category) {
                $category['children'] = $depth < $maxDepth ? $build((int) $category['id'], $depth + 1) : [];
                $nodes[] = $category;
            }
            return $nodes;
        };
        return $build($parentId ?? 0, 0);
    }

    /** @return list<array<string,mixed>> flat list with an indented `label` for selects */
    public static function categoryFlat(bool $activeOnly = false, ?int $excludeId = null): array
    {
        $flat = [];
        $walk = static function (array $nodes, int $depth) use (&$walk, &$flat, $excludeId): void {
            foreach ($nodes as $node) {
                if ($excludeId !== null && (int) $node['id'] === $excludeId) {
                    continue; // do not let a category be moved inside itself
                }
                $node['label'] = str_repeat('— ', $depth) . $node['name'];
                $flat[] = $node;
                if (!empty($node['children'])) {
                    $walk($node['children'], $depth + 1);
                }
            }
        };
        $walk(self::categoryTree($activeOnly), 0);
        return $flat;
    }

    /** @return array<string,mixed>|null */
    public static function categoryBySlug(string $slug): ?array
    {
        $row = Database::one('SELECT * FROM categories WHERE slug = :slug LIMIT 1', ['slug' => $slug]);
        return $row === null ? null : self::mapCategory($row);
    }

    /** @return array<string,mixed>|null */
    public static function categoryById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = Database::one('SELECT * FROM categories WHERE id = :id LIMIT 1', ['id' => $id]);
        return $row === null ? null : self::mapCategory($row);
    }

    /**
     * A category plus every category beneath it.
     *
     * @return list<int>
     */
    public static function categoryDescendantIds(array $category): array
    {
        $id = (int) ($category['id'] ?? 0);
        if ($id <= 0) {
            return [];
        }
        // Walk the parent_id relationship instead of relying on the optional
        // materialised path. Older/imported category rows may have stale paths;
        // filtering by path then silently drops products from those subcategories.
        $children = [];
        foreach (self::categories(false) as $row) {
            $parent = (int) ($row['parent_id'] ?? 0);
            if ($parent > 0) {
                $children[$parent][] = (int) $row['id'];
            }
        }
        $ids = [];
        $queue = [$id];
        $seen = [];
        while ($queue !== []) {
            $current = (int) array_shift($queue);
            if ($current <= 0 || isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            $ids[] = $current;
            foreach ($children[$current] ?? [] as $childId) {
                $queue[] = $childId;
            }
        }
        return $ids;
    }

    /** Root → … → this category. @return list<array<string,mixed>> */
    public static function categoryBreadcrumb(array $category): array
    {
        $map = self::categoryMap(false);
        $trail = [];
        $cursor = $category;
        $guard = 0;
        while ($cursor !== null && $guard < 20) {
            $trail[] = $cursor;
            $parentId = $cursor['parent_id'] !== null ? (int) $cursor['parent_id'] : 0;
            $cursor = $parentId > 0 ? ($map[$parentId] ?? null) : null;
            $guard++;
        }
        return array_reverse($trail);
    }

    /** Product counts per category (direct + including descendants). */
    public static function categoryCounts(bool $activeOnly = true): array
    {
        $sql = "SELECT category_id, COUNT(*) AS total FROM products WHERE deleted_at IS NULL AND status = 'active' GROUP BY category_id";
        $rows = Database::all($sql);
        $direct = [];
        foreach ($rows as $row) {
            $direct[(int) $row['category_id']] = (int) $row['total'];
        }
        $categories = self::categories($activeOnly);
        $byParent = [];
        foreach ($categories as $category) {
            $parent = $category['parent_id'] !== null ? (int) $category['parent_id'] : 0;
            $byParent[$parent][] = (int) $category['id'];
        }
        $sum = static function (int $id) use (&$sum, $byParent, $direct): int {
            $total = $direct[$id] ?? 0;
            foreach ($byParent[$id] ?? [] as $child) {
                $total += $sum($child);
            }
            return $total;
        };
        $counts = [];
        foreach ($categories as $category) {
            $id = (int) $category['id'];
            $counts[$id] = ['id' => $id, 'direct' => $direct[$id] ?? 0, 'total' => $sum($id)];
        }
        return $counts;
    }

    /** @param array<string,mixed> $data */
    public static function createCategory(array $data): int
    {
        $parentId = !empty($data['parent_id']) ? (int) $data['parent_id'] : null;
        $depth = 0;
        $path = '';
        if ($parentId !== null) {
            $parent = self::categoryById($parentId);
            if ($parent === null) {
                throw new InvalidArgumentException('Parent category not found.');
            }
            $depth = (int) $parent['depth'] + 1;
            $path = rtrim((string) $parent['path'], '/') . '/' . $parentId;
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Category name is required.');
        }
        $id = Database::insert('categories', [
            'parent_id'        => $parentId,
            'name'             => $name,
            'slug'             => self::uniqueSlug('categories', (string) ($data['slug'] ?? slugify($name))),
            'description'      => isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
            'image_url'        => $data['image_url'] ?? null,
            'icon'             => (string) ($data['icon'] ?? 'bi-grid'),
            'color'            => (string) ($data['color'] ?? '#0c800f'),
            'position'         => (int) ($data['position'] ?? 0),
            'is_featured'      => (int) !empty($data['is_featured']),
            'is_active'        => (int) (!isset($data['is_active']) || $data['is_active']),
            'depth'            => $depth,
            'path'             => $path === '' ? '/' : $path . '/',
            'meta_title'       => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
        // Rebuild the materialised path including the new id.
        self::rebuildPaths();
        Audit::log('category.created', 'category', $id, ['name' => $name]);
        return $id;
    }

    /** @param array<string,mixed> $data */
    public static function updateCategory(int $id, array $data): void
    {
        $existing = self::categoryById($id);
        if ($existing === null) {
            throw new InvalidArgumentException('Category not found.');
        }
        $parentId = !empty($data['parent_id']) ? (int) $data['parent_id'] : null;
        if ($parentId !== null) {
            if ($parentId === $id) {
                throw new InvalidArgumentException('A category cannot be its own parent.');
            }
            $descendants = self::categoryDescendantIds($existing);
            if (in_array($parentId, $descendants, true)) {
                throw new InvalidArgumentException('A category cannot live inside one of its own children.');
            }
        }
        $name = trim((string) ($data['name'] ?? $existing['name']));
        if ($name === '') {
            throw new InvalidArgumentException('Category name is required.');
        }
        Database::update('categories', [
            'parent_id'        => $parentId,
            'name'             => $name,
            'slug'             => self::uniqueSlug('categories', (string) ($data['slug'] ?? slugify($name)), $id),
            'description'      => isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
            'image_url'        => $data['image_url'] ?? null,
            'icon'             => (string) ($data['icon'] ?? $existing['icon']),
            'color'            => (string) ($data['color'] ?? $existing['color']),
            'position'         => (int) ($data['position'] ?? $existing['position']),
            'is_featured'      => (int) !empty($data['is_featured']),
            'is_active'        => (int) (!isset($data['is_active']) || $data['is_active']),
            'meta_title'       => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'updated_at'       => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $id]);
        self::rebuildPaths();
        Audit::log('category.updated', 'category', $id, ['name' => $name]);
    }

    public static function deleteCategory(int $id): void
    {
        $children = Database::int('SELECT COUNT(*) FROM categories WHERE parent_id = :id', ['id' => $id]);
        if ($children > 0) {
            throw new RuntimeException('Move or delete the ' . $children . ' sub-categor' . ($children === 1 ? 'y' : 'ies') . ' first.');
        }
        $products = Database::int('SELECT COUNT(*) FROM products WHERE category_id = :pid AND deleted_at IS NULL', ['pid' => $id]);
        if ($products > 0) {
            throw new RuntimeException('This category still holds ' . $products . ' product(s). Move them first.');
        }
        Database::run('DELETE FROM categories WHERE id = :id', ['id' => $id]);
        self::rebuildPaths();
        Audit::log('category.deleted', 'category', $id);
    }

    /** Recompute depth + materialised path for every category. */
    public static function rebuildPaths(): void
    {
        $rows = Database::all('SELECT id, parent_id FROM categories');
        $byParent = [];
        foreach ($rows as $row) {
            $parent = $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;
            $byParent[$parent][] = (int) $row['id'];
        }
        $walk = static function (int $parent, string $prefix, int $depth) use (&$walk, $byParent): void {
            foreach ($byParent[$parent] ?? [] as $id) {
                $path = $prefix . '/' . $id . '/';
                Database::run(
                    'UPDATE categories SET path = :path, depth = :depth WHERE id = :id',
                    ['path' => $path, 'depth' => $depth, 'id' => $id]
                );
                $walk($id, rtrim($path, '/'), $depth + 1);
            }
        };
        $walk(0, '', 0);
    }

    public static function featuredCategories(int $limit = 8): array
    {
        $rows = Database::all(
            'SELECT * FROM categories WHERE is_active = 1 AND is_featured = 1 ORDER BY position ASC, id ASC LIMIT ' . max(1, min(24, $limit))
        );
        $counts = self::categoryCounts(true);
        return array_map(static function (array $row) use ($counts): array {
            $category = self::mapCategory($row);
            $category['product_count'] = (int) ($counts[(int) $category['id']]['total'] ?? 0);
            return $category;
        }, $rows);
    }

    /* =====================================================================
       Brands
       ===================================================================== */

    /** @return list<array<string,mixed>> */
    public static function brands(bool $withCounts = false): array
    {
        $rows = Database::all('SELECT * FROM brands ORDER BY name ASC');
        if (!$withCounts) {
            return $rows;
        }
        $counts = [];
        foreach (Database::all(
            "SELECT brand_id, COUNT(*) AS total FROM products WHERE status = 'active' AND deleted_at IS NULL GROUP BY brand_id"
        ) as $row) {
            $counts[(int) $row['brand_id']] = (int) $row['total'];
        }
        return array_map(static function (array $row) use ($counts): array {
            $row['product_count'] = $counts[(int) $row['id']] ?? 0;
            return $row;
        }, $rows);
    }

    /** @return array<string,mixed>|null */
    public static function brandBySlug(string $slug): ?array
    {
        return Database::one('SELECT * FROM brands WHERE slug = :slug LIMIT 1', ['slug' => $slug]);
    }

    public static function brandById(int $id): ?array
    {
        return Database::one('SELECT * FROM brands WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    /** @param array<string,mixed> $data */
    public static function createBrand(array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Brand name is required.');
        }
        $id = Database::insert('brands', [
            'name'       => $name,
            'slug'       => self::uniqueSlug('brands', (string) ($data['slug'] ?? slugify($name))),
            'logo_url'   => $data['logo_url'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        Audit::log('brand.created', 'brand', $id, ['name' => $name]);
        return $id;
    }

    /** @param array<string,mixed> $data */
    public static function updateBrand(int $id, array $data): void
    {
        $existing = self::brandById($id);
        if ($existing === null) {
            throw new InvalidArgumentException('Brand not found.');
        }
        $name = trim((string) ($data['name'] ?? $existing['name']));
        Database::update('brands', [
            'name'     => $name,
            'slug'     => self::uniqueSlug('brands', (string) ($data['slug'] ?? slugify($name)), $id),
            'logo_url' => $data['logo_url'] ?? $existing['logo_url'],
        ], 'id = :id', ['id' => $id]);
        Audit::log('brand.updated', 'brand', $id, ['name' => $name]);
    }

    public static function deleteBrand(int $id): void
    {
        $products = Database::int('SELECT COUNT(*) FROM products WHERE brand_id = :id AND deleted_at IS NULL', ['id' => $id]);
        if ($products > 0) {
            throw new RuntimeException('This brand is linked to ' . $products . ' product(s).');
        }
        Database::run('DELETE FROM brands WHERE id = :id', ['id' => $id]);
        Audit::log('brand.deleted', 'brand', $id);
    }

    /* =====================================================================
       Products
       ===================================================================== */

    /** @return array<string,mixed>|null */
    public static function product(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = Database::one(self::PRODUCT_SELECT . ' WHERE p.id = :id AND p.deleted_at IS NULL LIMIT 1', ['id' => $id]);
        return $row === null ? null : self::mapProduct($row);
    }

    /** @return array<string,mixed>|null */
    public static function productBySlug(string $slug, bool $activeOnly = false): ?array
    {
        $sql = self::PRODUCT_SELECT . ' WHERE p.slug = :slug AND p.deleted_at IS NULL';
        if ($activeOnly) {
            $sql .= " AND p.status = 'active'";
        }
        $row = Database::one($sql . ' LIMIT 1', ['slug' => $slug]);
        return $row === null ? null : self::mapProduct($row);
    }

    /** @return array<string,mixed>|null */
    public static function productBySku(string $sku): ?array
    {
        $row = Database::one(self::PRODUCT_SELECT . ' WHERE p.sku = :sku AND p.deleted_at IS NULL LIMIT 1', ['sku' => $sku]);
        return $row === null ? null : self::mapProduct($row);
    }

    /**
     * Hydrate products in the order the ids were given.
     *
     * @param list<int> $ids
     * @return list<array<string,mixed>>
     */
    public static function productsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id) => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::all(self::PRODUCT_SELECT . " WHERE p.id IN ({$placeholders}) AND p.deleted_at IS NULL", $ids);
        $mapped = [];
        foreach ($rows as $row) {
            $product = self::mapProduct($row);
            $mapped[$product['id']] = $product;
        }
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($mapped[$id])) {
                $ordered[] = $mapped[$id];
            }
        }
        return $ordered;
    }

    /** Normalise filter input from the query string. */
    public static function parseFilters(array $input): array
    {
        $sort = (string) ($input['sort'] ?? 'newest');
        $allowedSort = ['newest', 'price_asc', 'price_desc', 'rating', 'popular', 'name', 'discount'];
        if (!in_array($sort, $allowedSort, true)) {
            $sort = 'newest';
        }
        $priceMin = isset($input['min']) && $input['min'] !== '' ? max(0, (float) $input['min']) : null;
        $priceMax = isset($input['max']) && $input['max'] !== '' ? max(0, (float) $input['max']) : null;
        if ($priceMin !== null && $priceMax !== null && $priceMin > $priceMax) {
            [$priceMin, $priceMax] = [$priceMax, $priceMin];
        }
        return [
            'q'          => trim((string) ($input['q'] ?? '')),
            'category'   => trim((string) ($input['category'] ?? '')),
            'brand'      => array_values(array_filter(array_map('strval', (array) ($input['brand'] ?? [])))),
            'min'        => $priceMin,
            'max'        => $priceMax,
            'rating'     => isset($input['rating']) && $input['rating'] !== '' ? max(1, min(5, (int) $input['rating'])) : null,
            'in_stock'   => !empty($input['in_stock']),
            'on_sale'    => !empty($input['on_sale']),
            'featured'   => !empty($input['featured']),
            'condition'  => in_array($input['condition'] ?? '', ['new', 'used', 'refurbished'], true) ? (string) $input['condition'] : '',
            'sort'       => $sort,
            'page'       => max(1, (int) ($input['page'] ?? 1)),
        ];
    }

    /**
     * @param array<string,mixed> $filters parseFilters() output
     * @return array{rows:list<array<string,mixed>>,total:int,pager:array<string,mixed>,filters:array<string,mixed>}
     */
    public static function paginateProducts(array $filters): array
    {
        $per = max(4, min(60, (int) ($filters['per'] ?? (int) Config::get('app.products_per_page', 24))));
        [$where, $params] = self::buildWhere($filters);
        $join = ' LEFT JOIN brands b ON b.id = p.brand_id LEFT JOIN categories c ON c.id = p.category_id';
        $total = Database::int("SELECT COUNT(DISTINCT p.id) FROM products p{$join} WHERE {$where}", $params);
        $pager = paginate($total, (int) ($filters['page'] ?? 1), $per);
        $orderBy = self::orderBy((string) ($filters['sort'] ?? 'newest'), (string) ($filters['q'] ?? ''));
        $rows = Database::all(
            self::PRODUCT_SELECT . " WHERE {$where} ORDER BY {$orderBy} LIMIT {$pager['limit']} OFFSET {$pager['offset']}",
            $params
        );
        return [
            'rows'    => array_map([self::class, 'mapProduct'], $rows),
            'total'   => $total,
            'pager'   => $pager,
            'filters' => $filters,
        ];
    }

    /** Brand + price facets for the filter sidebar. */
    public static function productFacets(array $filters): array
    {
        [$where, $params] = self::buildWhere($filters);
        $join = ' LEFT JOIN brands b ON b.id = p.brand_id LEFT JOIN categories c ON c.id = p.category_id';
        $brands = Database::all(
            "SELECT b.id, b.name, b.slug, COUNT(DISTINCT p.id) AS total
               FROM products p{$join}
              WHERE {$where} AND b.id IS NOT NULL
           GROUP BY b.id, b.name, b.slug
           ORDER BY total DESC, b.name ASC
              LIMIT 18",
            $params
        );
        $bounds = Database::one(
            "SELECT MIN(p.price) AS min_price, MAX(p.price) AS max_price FROM products p{$join} WHERE {$where}",
            $params
        ) ?? ['min_price' => 0, 'max_price' => 0];
        return [
            'brands' => $brands,
            'min_price' => (float) ($bounds['min_price'] ?? 0),
            'max_price' => (float) ($bounds['max_price'] ?? 0),
        ];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private static function buildWhere(array $filters): array
    {
        $where = ["p.status = 'active'", 'p.deleted_at IS NULL'];
        $params = [];

        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
            $where[] = '(p.title LIKE :wq1 OR p.sku LIKE :wq2 OR p.tags LIKE :wq3 OR p.short_description LIKE :wq4 OR b.name LIKE :wq5)';
            $params['wq1'] = $like;
            $params['wq2'] = $like;
            $params['wq3'] = $like;
            $params['wq4'] = $like;
            $params['wq5'] = $like;
        }

        $categorySlug = (string) ($filters['category'] ?? '');
        if ($categorySlug !== '') {
            $category = self::categoryBySlug($categorySlug);
            if ($category === null) {
                $where[] = '1 = 0';
            } else {
                $ids = self::categoryDescendantIds($category);
                $ids = $ids !== [] ? $ids : [(int) $category['id']];
                $where[] = 'p.category_id IN (' . implode(',', array_map('intval', $ids)) . ')';
            }
        }

        $brands = array_values(array_filter(array_map('strval', (array) ($filters['brand'] ?? []))));
        if ($brands !== []) {
            $marks = [];
            foreach ($brands as $index => $slug) {
                $key = 'wb' . $index;
                $marks[] = ':' . $key;
                $params[$key] = $slug;
            }
            $where[] = 'b.slug IN (' . implode(',', $marks) . ')';
        }

        if (($filters['min'] ?? null) !== null) {
            $where[] = 'p.price >= :wmin';
            $params['wmin'] = (float) $filters['min'];
        }
        if (($filters['max'] ?? null) !== null) {
            $where[] = 'p.price <= :wmax';
            $params['wmax'] = (float) $filters['max'];
        }
        if (($filters['rating'] ?? null) !== null) {
            $where[] = 'p.rating >= :wrating';
            $params['wrating'] = (float) $filters['rating'];
        }
        if (!empty($filters['in_stock'])) {
            $where[] = 'p.stock > 0';
        }
        if (!empty($filters['on_sale'])) {
            $where[] = '(p.compare_at_price IS NOT NULL AND p.compare_at_price > p.price) OR p.is_flash_deal = 1';
        }
        if (!empty($filters['featured'])) {
            $where[] = 'p.is_featured = 1';
        }
        if (($filters['condition'] ?? '') !== '') {
            // `condition` is a reserved word in MySQL/MariaDB — always quote it.
            $where[] = 'p.`condition` = :wcondition';
            $params['wcondition'] = $filters['condition'];
        }

        return [implode(' AND ', $where), $params];
    }

    private static function orderBy(string $sort, string $term = ''): string
    {
        return match ($sort) {
            'price_asc'  => 'p.price ASC, p.id DESC',
            'price_desc' => 'p.price DESC, p.id DESC',
            'rating'     => 'p.rating DESC, p.review_count DESC, p.id DESC',
            'popular'    => 'p.sold_count DESC, p.view_count DESC, p.id DESC',
            'name'       => 'p.title ASC',
            'discount'   => '((p.compare_at_price - p.price) / NULLIF(p.compare_at_price, 0)) DESC, p.id DESC',
            'newest'     => $term !== '' ? 'p.is_featured DESC, p.sold_count DESC, p.id DESC' : 'p.created_at DESC, p.id DESC',
            default      => 'p.created_at DESC, p.id DESC',
        };
    }

    /** @return list<array<string,mixed>> */
    public static function featuredProducts(int $limit = 8): array
    {
        return self::collection(
            "SELECT p.*, c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon, b.name AS brand_name, b.slug AS brand_slug
               FROM products p
          LEFT JOIN categories c ON c.id = p.category_id
          LEFT JOIN brands b ON b.id = p.brand_id
              WHERE p.status = 'active' AND p.deleted_at IS NULL AND p.is_featured = 1 AND p.stock > 0
           ORDER BY p.sold_count DESC, p.id DESC LIMIT " . max(1, $limit)
        );
    }

    public static function newArrivals(int $limit = 8): array
    {
        return self::collection(
            "SELECT p.*, c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon, b.name AS brand_name, b.slug AS brand_slug
               FROM products p
          LEFT JOIN categories c ON c.id = p.category_id
          LEFT JOIN brands b ON b.id = p.brand_id
              WHERE p.status = 'active' AND p.deleted_at IS NULL
           ORDER BY p.created_at DESC, p.id DESC LIMIT " . max(1, $limit)
        );
    }

    public static function trendingProducts(int $limit = 8): array
    {
        return self::collection(
            "SELECT p.*, c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon, b.name AS brand_name, b.slug AS brand_slug
               FROM products p
          LEFT JOIN categories c ON c.id = p.category_id
          LEFT JOIN brands b ON b.id = p.brand_id
              WHERE p.status = 'active' AND p.deleted_at IS NULL
           ORDER BY p.view_count DESC, p.sold_count DESC LIMIT " . max(1, $limit)
        );
    }

    public static function dealProducts(int $limit = 8): array
    {
        return self::collection(
            "SELECT p.*, c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon, b.name AS brand_name, b.slug AS brand_slug
               FROM products p
          LEFT JOIN categories c ON c.id = p.category_id
          LEFT JOIN brands b ON b.id = p.brand_id
              WHERE p.status = 'active' AND p.deleted_at IS NULL AND p.stock > 0
                AND (p.is_flash_deal = 1 OR (p.compare_at_price IS NOT NULL AND p.compare_at_price > p.price))
           ORDER BY ((p.compare_at_price - p.price) / NULLIF(p.compare_at_price, 0)) DESC
              LIMIT " . max(1, $limit)
        );
    }

    public static function bestSellers(int $limit = 8): array
    {
        return self::collection(
            "SELECT p.*, c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon, b.name AS brand_name, b.slug AS brand_slug
               FROM products p
          LEFT JOIN categories c ON c.id = p.category_id
          LEFT JOIN brands b ON b.id = p.brand_id
              WHERE p.status = 'active' AND p.deleted_at IS NULL AND p.sold_count > 0
           ORDER BY p.sold_count DESC LIMIT " . max(1, $limit)
        );
    }

    /** Same category first, then popular fallbacks. @return list<array<string,mixed>> */
    public static function relatedProducts(array $product, int $limit = 8): array
    {
        $limit = max(1, min(20, $limit));
        $rows = self::collection(
            "SELECT p.*, c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon, b.name AS brand_name, b.slug AS brand_slug
               FROM products p
          LEFT JOIN categories c ON c.id = p.category_id
          LEFT JOIN brands b ON b.id = p.brand_id
              WHERE p.status = 'active' AND p.deleted_at IS NULL AND p.stock > 0
                AND p.id <> :selfId AND p.category_id = :catId
           ORDER BY p.sold_count DESC, p.rating DESC LIMIT " . $limit,
            ['selfId' => (int) $product['id'], 'catId' => (int) $product['category_id']]
        );
        if (count($rows) >= 4) {
            return $rows;
        }
        $seen = array_merge([(int) $product['id']], array_map(static fn ($row) => (int) $row['id'], $rows));
        $fill = self::collection(
            "SELECT p.*, c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon, b.name AS brand_name, b.slug AS brand_slug
               FROM products p
          LEFT JOIN categories c ON c.id = p.category_id
          LEFT JOIN brands b ON b.id = p.brand_id
              WHERE p.status = 'active' AND p.deleted_at IS NULL AND p.stock > 0
                AND p.id NOT IN (" . implode(',', array_map('intval', $seen)) . ')
           ORDER BY p.sold_count DESC LIMIT ' . ($limit - count($rows))
        );
        return array_merge($rows, $fill);
    }

    /** @return list<array<string,mixed>> */
    private static function collection(string $sql, array $params = []): array
    {
        return array_map([self::class, 'mapProduct'], Database::all($sql, $params));
    }

    /** Header search suggestions. @return list<array<string,mixed>> */
    public static function productSuggestions(string $term, int $limit = 6): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 3) {
            return [];
        }
        $like = '%' . $term . '%';
        return self::collection(
            "SELECT p.*, c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon, b.name AS brand_name, b.slug AS brand_slug
               FROM products p
          LEFT JOIN categories c ON c.id = p.category_id
          LEFT JOIN brands b ON b.id = p.brand_id
              WHERE p.status = 'active' AND p.deleted_at IS NULL
                AND (p.title LIKE :sq1 OR p.sku LIKE :sq2 OR p.tags LIKE :sq3)
           ORDER BY (p.title LIKE :sq4) DESC, p.sold_count DESC
              LIMIT " . max(1, min(12, $limit)),
            ['sq1' => $like, 'sq2' => $like, 'sq3' => $like, 'sq4' => $term . '%']
        );
    }

    /** @return list<array<string,mixed>> */
    public static function categorySuggestions(string $term, int $limit = 4): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 3) {
            return [];
        }
        $rows = Database::all(
            'SELECT * FROM categories WHERE is_active = 1 AND name LIKE :c ORDER BY position ASC LIMIT ' . max(1, min(10, $limit)),
            ['c' => '%' . $term . '%']
        );
        return array_map([self::class, 'mapCategory'], $rows);
    }

    /** @return list<array<string,mixed>> brands whose name matches the term */
    public static function brandSuggestions(string $term, int $limit = 3): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 3) {
            return [];
        }
        return Database::all(
            'SELECT id, name, slug, logo_url FROM brands WHERE name LIKE :b ORDER BY name ASC LIMIT ' . max(1, min(10, $limit)),
            ['b' => '%' . $term . '%']
        );
    }

    /** How many active products match a search term (for "see all N results"). */
    public static function suggestionCount(string $term): int
    {
        $term = trim($term);
        if (mb_strlen($term) < 3) {
            return 0;
        }
        $like = '%' . $term . '%';
        return Database::int(
            "SELECT COUNT(*) FROM products p LEFT JOIN brands b ON b.id = p.brand_id
              WHERE p.status = 'active' AND p.deleted_at IS NULL
                AND (p.title LIKE :c1 OR p.sku LIKE :c2 OR p.tags LIKE :c3 OR b.name LIKE :c4)",
            ['c1' => $like, 'c2' => $like, 'c3' => $like, 'c4' => $like]
        );
    }

    public static function incrementView(int $productId): void
    {
        try {
            Database::run('UPDATE products SET view_count = view_count + 1 WHERE id = :id', ['id' => $productId]);
        } catch (Throwable $e) {
            // A failed counter must never break a product page.
        }
    }

    public static function productCount(?string $status = null): int
    {
        $sql = 'SELECT COUNT(*) FROM products WHERE deleted_at IS NULL';
        $params = [];
        if ($status !== null) {
            $sql .= ' AND status = :status';
            $params['status'] = $status;
        }
        return Database::int($sql, $params);
    }

    /**
     * Recent approved reviews with product context — powers the "what shoppers
     * say" strip on the storefront. Never throws: a store with no reviews (or a
     * missing table during setup) simply renders nothing.
     *
     * @return list<array<string,mixed>>
     */
    public static function recentReviews(int $limit = 3, int $minRating = 4): array
    {
        $limit = max(1, min(12, $limit));
        $minRating = max(1, min(5, $minRating));
        try {
            return Database::all(
                "SELECT r.rating, r.title, r.body, r.author_name, r.created_at,
                        p.title AS product_title, p.slug AS product_slug
                   FROM reviews r
                   JOIN products p ON p.id = r.product_id
                  WHERE r.status = 'approved' AND r.rating >= :min AND r.body <> ''
               ORDER BY r.created_at DESC
                  LIMIT " . $limit,
                ['min' => $minRating]
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Store-wide trust figures: how many approved reviews and the average score.
     * (Catalog::reviewSummary() is the per-product breakdown used on product pages.)
     *
     * @return array{total:int,average:float}
     */
    public static function storeReviewSummary(): array
    {
        try {
            $row = Database::one("SELECT COUNT(*) AS total, COALESCE(AVG(rating), 0) AS average FROM reviews WHERE status = 'approved'");
        } catch (Throwable $e) {
            return ['total' => 0, 'average' => 0.0];
        }
        return [
            'total'   => (int) ($row['total'] ?? 0),
            'average' => round((float) ($row['average'] ?? 0), 1),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function lowStock(int $limit = 20): array
    {
        return self::collection(
            "SELECT p.*, c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon, b.name AS brand_name, b.slug AS brand_slug
               FROM products p
          LEFT JOIN categories c ON c.id = p.category_id
          LEFT JOIN brands b ON b.id = p.brand_id
              WHERE p.deleted_at IS NULL AND p.stock <= p.low_stock_alert
           ORDER BY p.stock ASC, p.sold_count DESC LIMIT " . max(1, min(100, $limit))
        );
    }

    /** Dashboard product list with admin-only columns. */
    public static function adminProducts(array $filters): array
    {
        $where = ['p.deleted_at IS NULL'];
        $params = [];
        if (!empty($filters['q'])) {
            $like = '%' . $filters['q'] . '%';
            $where[] = '(p.title LIKE :aq1 OR p.sku LIKE :aq2 OR p.tags LIKE :aq3 OR b.name LIKE :aq4 OR c.name LIKE :aq5)';
            $params['aq1'] = $like;
            $params['aq2'] = $like;
            $params['aq3'] = $like;
            $params['aq4'] = $like;
            $params['aq5'] = $like;
        }
        if (!empty($filters['status']) && in_array($filters['status'], ['active', 'draft', 'archived'], true)) {
            $where[] = 'p.status = :astatus';
            $params['astatus'] = $filters['status'];
        }
        if (!empty($filters['category'])) {
            $where[] = 'p.category_id = :acat';
            $params['acat'] = (int) $filters['category'];
        }
        if (!empty($filters['brand'])) {
            $where[] = 'p.brand_id = :abrand';
            $params['abrand'] = (int) $filters['brand'];
        }
        if (($filters['stock'] ?? '') === 'low') {
            $where[] = 'p.stock <= p.low_stock_alert AND p.stock > 0';
        } elseif (($filters['stock'] ?? '') === 'out') {
            $where[] = 'p.stock <= 0';
        }

        $whereSql = implode(' AND ', $where);
        $total = Database::int(
            "SELECT COUNT(*) FROM products p
          LEFT JOIN categories c ON c.id = p.category_id
          LEFT JOIN brands b ON b.id = p.brand_id
              WHERE {$whereSql}",
            $params
        );
        $pager = paginate($total, (int) ($filters['page'] ?? 1), 25);
        $sort = match ($filters['sort'] ?? 'newest') {
            'title'      => 'p.title ASC',
            'price_asc'  => 'p.price ASC',
            'price_desc' => 'p.price DESC',
            'stock'      => 'p.stock ASC',
            'sold'       => 'p.sold_count DESC',
            default      => 'p.created_at DESC',
        };
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
        return ['rows' => array_map([self::class, 'mapProduct'], $rows), 'total' => $total, 'pager' => $pager];
    }

    /**
     * Create or update a product from the dashboard form.
     *
     * @param array<string,mixed> $data
     */
    public static function saveProduct(array $data, ?int $id = null): int
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Product title is required.');
        }
        $price = (float) ($data['price'] ?? 0);
        if ($price < 0) {
            throw new InvalidArgumentException('Price cannot be negative.');
        }
        $categoryId = (int) ($data['category_id'] ?? 0);
        $category = self::categoryById($categoryId);
        if ($category === null) {
            throw new InvalidArgumentException('Choose a valid category.');
        }
        $parentCategoryId = (int) ($data['parent_category_id'] ?? 0);
        if ($parentCategoryId > 0 && $categoryId !== $parentCategoryId
            && (int) ($category['parent_id'] ?? 0) !== $parentCategoryId) {
            throw new InvalidArgumentException('Choose a subcategory belonging to the selected parent category.');
        }
        $sku = strtoupper(trim((string) ($data['sku'] ?? '')));
        if ($sku === '') {
            throw new InvalidArgumentException('SKU is required.');
        }
        $dupe = Database::exists('SELECT 1 FROM products WHERE sku = :sku AND id <> :id LIMIT 1', [
            'sku' => $sku,
            'id'  => $id ?? 0,
        ]);
        if ($dupe) {
            throw new InvalidArgumentException('That SKU is already used by another product.');
        }

        // Accept Google Drive share links and ordinary product-page image URLs,
        // normalise them into one stored shape and cap the full gallery at 20 images.
        $gallery = [];
        foreach ((array) ($data['gallery'] ?? []) as $rawImage) {
            $normalized = normalize_image_url(is_string($rawImage) ? $rawImage : null);
            if ($normalized !== null && !in_array($normalized, $gallery, true)) {
                $gallery[] = $normalized;
            }
        }
        // image_url is rendered as the first gallery item, leaving at most 19 extras.
        $gallery = array_slice($gallery, 0, !empty($data['image_url']) ? 19 : 20);
        $specs = [];
        foreach ((array) ($data['specs'] ?? []) as $entry) {
            $name = trim((string) ($entry['name'] ?? ''));
            $value = trim((string) ($entry['value'] ?? ''));
            if ($name !== '' && $value !== '') {
                $specs[$name] = $value;
            }
        }
        $tags = (array) ($data['tags'] ?? []);
        $tags = array_values(array_filter(array_map(static fn ($tag) => trim((string) $tag), $tags)));
        $status = in_array($data['status'] ?? '', ['active', 'draft', 'archived'], true) ? (string) $data['status'] : 'draft';

        $fields = [
            'title'             => mb_substr($title, 0, 200),
            'slug'              => self::uniqueSlug('products', (string) ($data['slug'] ?? slugify($title)), $id),
            'sku'               => mb_substr($sku, 0, 60),
            'category_id'       => $categoryId,
            'brand_id'          => !empty($data['brand_id']) ? (int) $data['brand_id'] : null,
            'short_description' => isset($data['short_description']) && $data['short_description'] !== '' ? mb_substr((string) $data['short_description'], 0, 320) : null,
            'description'       => (string) ($data['description'] ?? ''),
            'price'             => round($price, 2),
            'compare_at_price'  => !empty($data['compare_at_price']) ? round((float) $data['compare_at_price'], 2) : null,
            'cost_price'        => !empty($data['cost_price']) ? round((float) $data['cost_price'], 2) : null,
            'stock'             => max(0, (int) ($data['stock'] ?? 0)),
            'low_stock_alert'   => max(0, (int) ($data['low_stock_alert'] ?? Settings::int('low_stock_threshold', 5))),
            'condition'         => in_array($data['condition'] ?? '', ['new', 'used', 'refurbished'], true) ? (string) $data['condition'] : 'new',
            'status'            => $status,
            'is_featured'       => (int) !empty($data['is_featured']),
            'is_flash_deal'     => (int) !empty($data['is_flash_deal']),
            'free_shipping'     => (int) !empty($data['free_shipping']),
            'shipping_fee'      => round(max(0.0, (float) ($data['shipping_fee'] ?? 0)), 2),
            'warranty'          => isset($data['warranty']) && $data['warranty'] !== '' ? mb_substr((string) $data['warranty'], 0, 120) : null,
            'weight_grams'      => !empty($data['weight_grams']) ? (int) $data['weight_grams'] : null,
            'image_url'         => normalize_image_url((string) ($data['image_url'] ?? '')),
            'gallery'           => $gallery === [] ? null : json_encode($gallery, JSON_UNESCAPED_SLASHES),
            'tags'              => $tags === [] ? null : mb_substr(implode(',', $tags), 0, 500),
            'specs'             => $specs === [] ? null : json_encode($specs, JSON_UNESCAPED_UNICODE),
            'meta_title'        => isset($data['meta_title']) && $data['meta_title'] !== '' ? mb_substr((string) $data['meta_title'], 0, 180) : null,
            'meta_description'  => isset($data['meta_description']) && $data['meta_description'] !== '' ? mb_substr((string) $data['meta_description'], 0, 320) : null,
            'updated_at'        => date('Y-m-d H:i:s'),
        ];

        if ($id === null) {
            $fields['uuid'] = uuid();
            $fields['created_at'] = date('Y-m-d H:i:s');
            $id = Database::insert('products', $fields);
            if ((int) $fields['stock'] > 0) {
                Inventory::record($id, (int) $fields['stock'], 'opening', 'Initial stock');
            }
            Audit::log('product.created', 'product', $id, ['title' => $fields['title'], 'sku' => $fields['sku']]);
            return $id;
        }

        $existing = self::product($id);
        if ($existing === null) {
            throw new InvalidArgumentException('Product not found.');
        }
        Database::update('products', $fields, 'id = :id', ['id' => $id]);
        Audit::log('product.updated', 'product', $id, ['title' => $fields['title']]);
        return $id;
    }

    /** Soft delete: keeps order history intact. */
    public static function deleteProduct(int $id): void
    {
        Database::run(
            'UPDATE products SET deleted_at = :now, status = :status, updated_at = :now2 WHERE id = :id',
            ['now' => date('Y-m-d H:i:s'), 'status' => 'archived', 'now2' => date('Y-m-d H:i:s'), 'id' => $id]
        );
        Audit::log('product.deleted', 'product', $id);
    }

    /** Guarantee a unique slug for a table. */
    public static function uniqueSlug(string $table, string $slug, ?int $exceptId = null): string
    {
        if (!in_array($table, ['products', 'categories', 'brands'], true)) {
            throw new InvalidArgumentException('Unknown table for slug.');
        }
        $slug = slugify($slug);
        $candidate = $slug;
        $suffix = 2;
        while (true) {
            $sql = "SELECT 1 FROM `{$table}` WHERE slug = :slug";
            $params = ['slug' => $candidate];
            if ($exceptId !== null) {
                $sql .= ' AND id <> :id';
                $params['id'] = $exceptId;
            }
            if (!Database::exists($sql . ' LIMIT 1', $params)) {
                return $candidate;
            }
            $candidate = $slug . '-' . $suffix;
            $suffix++;
            if ($suffix > 200) {
                return $slug . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
            }
        }
    }

    /* =====================================================================
       Reviews
       ===================================================================== */

    /** @return list<array<string,mixed>> */
    public static function reviews(int $productId, int $limit = 10, int $offset = 0): array
    {
        return Database::all(
            "SELECT * FROM reviews WHERE product_id = :pid AND status = 'approved'
              ORDER BY created_at DESC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            ['pid' => $productId]
        );
    }

    public static function reviewCount(int $productId): int
    {
        return Database::int(
            "SELECT COUNT(*) FROM reviews WHERE product_id = :pid AND status = 'approved'",
            ['pid' => $productId]
        );
    }

    /** @return array{average:float,count:int,stars:array<int,int>,verified:int} */
    public static function reviewSummary(int $productId): array
    {
        $rows = Database::all(
            "SELECT rating, COUNT(*) AS total FROM reviews
              WHERE product_id = :pid AND status = 'approved'
           GROUP BY rating",
            ['pid' => $productId]
        );
        $stars = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        $total = 0;
        $sum = 0;
        foreach ($rows as $row) {
            $rating = max(1, min(5, (int) $row['rating']));
            $count = (int) $row['total'];
            $stars[$rating] = $count;
            $total += $count;
            $sum += $rating * $count;
        }
        $verified = Database::int(
            "SELECT COUNT(DISTINCT r.user_id) FROM reviews r
               JOIN orders o ON o.user_id = r.user_id
               JOIN order_items oi ON oi.order_id = o.id AND oi.product_id = r.product_id
              WHERE r.product_id = :pid AND r.status = 'approved' AND r.user_id IS NOT NULL",
            ['pid' => $productId]
        );
        return [
            'average'  => $total > 0 ? round($sum / $total, 2) : 0.0,
            'count'    => $total,
            'stars'    => $stars,
            'verified' => $verified,
        ];
    }

    /** Recalculate the denormalised rating columns on the product row. */
    public static function refreshReviewStats(int $productId): void
    {
        $row = Database::one(
            "SELECT COUNT(*) AS c, COALESCE(AVG(rating), 0) AS a FROM reviews WHERE product_id = :pid AND status = 'approved'",
            ['pid' => $productId]
        ) ?? ['c' => 0, 'a' => 0];
        Database::run(
            'UPDATE products SET rating = :rating, review_count = :count WHERE id = :id',
            ['rating' => round((float) $row['a'], 2), 'count' => (int) $row['c'], 'id' => $productId]
        );
    }

    /** Moderation queue for the dashboard. */
    public static function adminReviews(array $filters = []): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($filters['status']) && in_array($filters['status'], ['pending', 'approved', 'rejected'], true)) {
            $where[] = 'r.status = :rstatus';
            $params['rstatus'] = $filters['status'];
        }
        if (!empty($filters['q'])) {
            $like = '%' . $filters['q'] . '%';
            $where[] = '(p.title LIKE :rq1 OR r.author_name LIKE :rq2 OR r.body LIKE :rq3)';
            $params['rq1'] = $like;
            $params['rq2'] = $like;
            $params['rq3'] = $like;
        }
        $whereSql = implode(' AND ', $where);
        $total = Database::int(
            "SELECT COUNT(*) FROM reviews r JOIN products p ON p.id = r.product_id WHERE {$whereSql}",
            $params
        );
        $pager = paginate($total, (int) ($filters['page'] ?? 1), 20);
        $rows = Database::all(
            "SELECT r.*, p.title AS product_title, p.slug AS product_slug, u.email AS author_email
               FROM reviews r
               JOIN products p ON p.id = r.product_id
          LEFT JOIN users u ON u.id = r.user_id
              WHERE {$whereSql}
           ORDER BY FIELD(r.status, 'pending') DESC, r.created_at DESC
              LIMIT {$pager['limit']} OFFSET {$pager['offset']}",
            $params
        );
        return ['rows' => $rows, 'total' => $total, 'pager' => $pager];
    }

    /* =====================================================================
       Mappers
       ===================================================================== */

    public static function mapCategory(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['parent_id'] = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
        $row['position'] = (int) ($row['position'] ?? 0);
        $row['depth'] = (int) ($row['depth'] ?? 0);
        $row['is_featured'] = (int) ($row['is_featured'] ?? 0);
        $row['is_active'] = (int) ($row['is_active'] ?? 1);
        return $row;
    }

    public static function mapProduct(array $row): array
    {
        foreach (['id', 'category_id', 'brand_id', 'stock', 'low_stock_alert', 'is_featured', 'is_flash_deal', 'free_shipping', 'sold_count', 'view_count', 'review_count', 'weight_grams'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int) $row[$key];
            }
        }
        foreach (['price', 'compare_at_price', 'cost_price', 'shipping_fee', 'rating'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (float) $row[$key];
            }
        }
        $row['stock'] = max(0, (int) ($row['stock'] ?? 0));
        $row['rating'] = round((float) ($row['rating'] ?? 0), 2);
        $row['low_stock'] = $row['stock'] > 0 && $row['stock'] <= ($row['low_stock_alert'] ?? 5);

        $gallery = json_decode((string) ($row['gallery'] ?? ''), true);
        $images = is_array($gallery) ? array_values(array_filter($gallery, 'is_string')) : [];
        if (!empty($row['image_url'])) {
            array_unshift($images, (string) $row['image_url']);
        }
        // Normalise on read as well: rows saved by older image editors kept
        // raw paste, and mixed Drive/web links must both keep working.
        $images = array_values(array_unique(array_filter(array_map('normalize_image_url', $images))));
        $row['images'] = array_slice($images, 0, 20);
        $row['image'] = $row['images'][0] ?? null;

        $specs = json_decode((string) ($row['specs'] ?? ''), true);
        $row['specs'] = is_array($specs) ? $specs : [];
        $row['tags_array'] = array_values(array_filter(array_map('trim', explode(',', (string) ($row['tags'] ?? '')))));

        $row['discount_percent'] = discount_percent($row['price'] ?? 0, $row['compare_at_price'] ?? null);
        $row['in_stock'] = $row['stock'] > 0;
        return $row;
    }
}

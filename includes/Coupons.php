<?php
declare(strict_types=1);

/**
 * Discount codes.
 *
 * Supported types: percent, fixed, free_shipping.
 * Guard rails: active window, global usage cap, per-customer cap, minimum spend,
 * optional category restriction and a cap on percent discounts.
 */
final class Coupons
{
    /** @return array<string,mixed>|null */
    public static function findByCode(string $code): ?array
    {
        $row = Database::one('SELECT * FROM coupons WHERE code = :code LIMIT 1', ['code' => strtoupper(trim($code))]);
        return $row === null ? null : self::map($row);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $row = Database::one('SELECT * FROM coupons WHERE id = :id LIMIT 1', ['id' => $id]);
        return $row === null ? null : self::map($row);
    }

    /** @return list<array<string,mixed>> */
    public static function active(int $limit = 5): array
    {
        $rows = Database::all(
            "SELECT * FROM coupons
              WHERE is_active = 1
                AND (starts_at IS NULL OR starts_at <= NOW())
                AND (expires_at IS NULL OR expires_at >= NOW())
                AND (max_uses = 0 OR used_count < max_uses)
           ORDER BY created_at DESC LIMIT " . max(1, $limit)
        );
        return array_map([self::class, 'map'], $rows);
    }

    /**
     * Validate a code against the current basket.
     *
     * @param array{subtotal:float,user_id:?int,items:list<array<string,mixed>>} $context
     * @return array{valid:bool,message:string,coupon:?array,discount:float,free_shipping:bool}
     */
    public static function validate(string $code, array $context): array
    {
        $coupon = self::findByCode($code);
        if ($coupon === null) {
            return ['valid' => false, 'message' => 'That coupon code is not recognised.', 'coupon' => null, 'discount' => 0.0, 'free_shipping' => false];
        }
        if (!$coupon['is_active']) {
            return ['valid' => false, 'message' => 'This coupon is no longer active.', 'coupon' => null, 'discount' => 0.0, 'free_shipping' => false];
        }
        $now = time();
        if (!empty($coupon['starts_at']) && strtotime((string) $coupon['starts_at']) > $now) {
            return ['valid' => false, 'message' => 'This coupon is not active yet.', 'coupon' => null, 'discount' => 0.0, 'free_shipping' => false];
        }
        if (!empty($coupon['expires_at']) && strtotime((string) $coupon['expires_at']) < $now) {
            return ['valid' => false, 'message' => 'This coupon has expired.', 'coupon' => null, 'discount' => 0.0, 'free_shipping' => false];
        }
        if ($coupon['max_uses'] > 0 && $coupon['used_count'] >= $coupon['max_uses']) {
            return ['valid' => false, 'message' => 'This coupon has reached its usage limit.', 'coupon' => null, 'discount' => 0.0, 'free_shipping' => false];
        }
        if ($coupon['min_spend'] > 0 && $context['subtotal'] < $coupon['min_spend']) {
            return [
                'valid' => false,
                'message' => 'Spend at least ' . money($coupon['min_spend']) . ' to use this coupon.',
                'coupon' => null,
                'discount' => 0.0,
                'free_shipping' => false,
            ];
        }
        if (!empty($coupon['per_user_limit']) && $coupon['per_user_limit'] > 0 && $context['user_id'] !== null) {
            $used = Database::int(
                'SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = :cid AND user_id = :uid',
                ['cid' => $coupon['id'], 'uid' => $context['user_id']]
            );
            if ($used >= $coupon['per_user_limit']) {
                return ['valid' => false, 'message' => 'You have already used this coupon.', 'coupon' => null, 'discount' => 0.0, 'free_shipping' => false];
            }
        }

        $eligible = self::eligibleSubtotal($coupon, $context['items'] ?? []);
        if ($coupon['applies_to_category_id'] !== null && $eligible <= 0) {
            return ['valid' => false, 'message' => 'This coupon does not apply to the items in your cart.', 'coupon' => null, 'discount' => 0.0, 'free_shipping' => false];
        }

        [$discount, $freeShipping] = self::calculate($coupon, $eligible, $context['subtotal']);
        if ($discount <= 0 && !$freeShipping) {
            return ['valid' => false, 'message' => 'This coupon does not reduce your current total.', 'coupon' => null, 'discount' => 0.0, 'free_shipping' => false];
        }

        return [
            'valid' => true,
            'message' => $coupon['type'] === 'free_shipping'
                ? 'Free shipping applied.'
                : 'Coupon applied — you saved ' . money($discount) . '.',
            'coupon' => $coupon,
            'discount' => $discount,
            'free_shipping' => $freeShipping,
        ];
    }

    /**
     * @param array<string,mixed> $coupon
     * @return array{0:float,1:bool} discount, free-shipping flag
     */
    public static function calculate(array $coupon, float $eligibleSubtotal, float $cartSubtotal): array
    {
        $base = max(0.0, $eligibleSubtotal > 0 ? $eligibleSubtotal : $cartSubtotal);
        switch ($coupon['type']) {
            case 'percent':
                $discount = $base * ((float) $coupon['value'] / 100);
                if (!empty($coupon['max_discount']) && $coupon['max_discount'] > 0) {
                    $discount = min($discount, (float) $coupon['max_discount']);
                }
                return [round(min($discount, $cartSubtotal), 2), false];
            case 'fixed':
                return [round(min((float) $coupon['value'], $cartSubtotal), 2), false];
            case 'free_shipping':
                return [0.0, true];
            default:
                return [0.0, false];
        }
    }

    /** Sum of cart lines the coupon is allowed to discount. */
    public static function eligibleSubtotal(array $coupon, array $items): float
    {
        $categoryId = $coupon['applies_to_category_id'] ?? null;
        if ($categoryId === null) {
            $total = 0.0;
            foreach ($items as $item) {
                $total += (float) ($item['line_total'] ?? 0);
            }
            return round($total, 2);
        }
        $category = Catalog::categoryById((int) $categoryId);
        if ($category === null) {
            return 0.0;
        }
        $ids = Catalog::categoryDescendantIds($category);
        $total = 0.0;
        foreach ($items as $item) {
            $product = $item['product'] ?? [];
            if (in_array((int) ($product['category_id'] ?? 0), $ids, true)) {
                $total += (float) ($item['line_total'] ?? 0);
            }
        }
        return round($total, 2);
    }

    public static function recordRedemption(int $couponId, ?int $userId, int $orderId, float $amount): void
    {
        Database::run('UPDATE coupons SET used_count = used_count + 1 WHERE id = :id', ['id' => $couponId]);
        Database::insert('coupon_redemptions', [
            'coupon_id' => $couponId,
            'user_id' => $userId,
            'order_id' => $orderId,
            'amount' => $amount,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /* ------------------------------------------------------------------ admin */

    public static function adminList(array $filters = []): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($filters['q'])) {
            $where[] = '(code LIKE :cq OR description LIKE :cq2)';
            $params['cq'] = '%' . $filters['q'] . '%';
            $params['cq2'] = '%' . $filters['q'] . '%';
        }
        if (($filters['status'] ?? '') === 'active') {
            $where[] = 'is_active = 1 AND (expires_at IS NULL OR expires_at >= NOW())';
        } elseif (($filters['status'] ?? '') === 'expired') {
            $where[] = 'expires_at IS NOT NULL AND expires_at < NOW()';
        } elseif (($filters['status'] ?? '') === 'inactive') {
            $where[] = 'is_active = 0';
        }
        $whereSql = implode(' AND ', $where);
        $total = Database::int("SELECT COUNT(*) FROM coupons WHERE {$whereSql}", $params);
        $pager = paginate($total, (int) ($filters['page'] ?? 1), 20);
        $rows = Database::all(
            "SELECT * FROM coupons WHERE {$whereSql} ORDER BY created_at DESC LIMIT {$pager['limit']} OFFSET {$pager['offset']}",
            $params
        );
        return ['rows' => array_map([self::class, 'map'], $rows), 'total' => $total, 'pager' => $pager];
    }

    /** @param array<string,mixed> $data */
    public static function save(array $data, ?int $id = null): int
    {
        $row = [
            'code' => strtoupper(trim((string) $data['code'])),
            'description' => substr(trim((string) ($data['description'] ?? '')), 0, 190),
            'type' => in_array($data['type'] ?? '', ['percent', 'fixed', 'free_shipping'], true) ? $data['type'] : 'percent',
            'value' => (float) ($data['value'] ?? 0),
            'min_spend' => (float) ($data['min_spend'] ?? 0),
            'max_discount' => !empty($data['max_discount']) ? (float) $data['max_discount'] : null,
            'max_uses' => (int) ($data['max_uses'] ?? 0),
            'per_user_limit' => (int) ($data['per_user_limit'] ?? 1),
            'is_active' => (int) (!empty($data['is_active'])),
            'starts_at' => !empty($data['starts_at']) ? date('Y-m-d H:i:s', strtotime((string) $data['starts_at'])) : null,
            'expires_at' => !empty($data['expires_at']) ? date('Y-m-d H:i:s', strtotime((string) $data['expires_at'] . ' 23:59:59')) : null,
            'applies_to_category_id' => !empty($data['applies_to_category_id']) ? (int) $data['applies_to_category_id'] : null,
        ];
        if ($row['type'] === 'free_shipping') {
            $row['value'] = 0;
        }
        if ($id === null) {
            $row['used_count'] = 0;
            $row['created_at'] = date('Y-m-d H:i:s');
            return Database::insert('coupons', $row);
        }
        Database::update('coupons', $row, 'id = :id', ['id' => $id]);
        return $id;
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM coupon_redemptions WHERE coupon_id = :id', ['id' => $id]);
        Database::run('DELETE FROM coupons WHERE id = :id', ['id' => $id]);
    }

    public static function codeExists(string $code, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM coupons WHERE code = :code';
        $params = ['code' => strtoupper(trim($code))];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        return Database::exists($sql . ' LIMIT 1', $params);
    }

    public static function map(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['value'] = (float) $row['value'];
        $row['min_spend'] = (float) $row['min_spend'];
        $row['max_discount'] = $row['max_discount'] !== null ? (float) $row['max_discount'] : null;
        $row['max_uses'] = (int) $row['max_uses'];
        $row['used_count'] = (int) $row['used_count'];
        $row['per_user_limit'] = (int) ($row['per_user_limit'] ?? 1);
        $row['is_active'] = (int) $row['is_active'];
        $row['applies_to_category_id'] = $row['applies_to_category_id'] !== null ? (int) $row['applies_to_category_id'] : null;
        $row['label'] = match ($row['type']) {
            'percent' => rtrim(rtrim(number_format((float) $row['value'], 1), '0'), '.') . '% off',
            'fixed' => money((float) $row['value']) . ' off',
            'free_shipping' => 'Free shipping',
            default => 'Discount',
        };
        return $row;
    }
}

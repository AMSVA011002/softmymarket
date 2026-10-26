<?php
declare(strict_types=1);

/**
 * Shopping cart.
 *
 * Guests keep their basket in the session; signed-in shoppers get a persistent
 * cart in the database so it follows them between devices. On sign-in the guest
 * basket is merged into the persistent one (quantities are summed and clamped to
 * available stock).
 */
final class Cart
{
    private const SESSION_KEY = '_cart';

    /** @return array<int,int> product_id => quantity */
    public static function raw(): array
    {
        if (Auth::check()) {
            $rows = Database::all('SELECT product_id, quantity FROM cart_items WHERE user_id = :uid', ['uid' => Auth::id()]);
            $items = [];
            foreach ($rows as $row) {
                $items[(int) $row['product_id']] = (int) $row['quantity'];
            }
            return $items;
        }
        $session = $_SESSION[self::SESSION_KEY] ?? [];
        if (!is_array($session)) {
            return [];
        }
        $items = [];
        foreach ($session as $productId => $qty) {
            if ((int) $qty > 0) {
                $items[(int) $productId] = (int) $qty;
            }
        }
        return $items;
    }

    public static function count(): int
    {
        return array_sum(self::raw());
    }

    public static function distinctCount(): int
    {
        return count(self::raw());
    }

    public static function add(int $productId, int $quantity = 1): array
    {
        $quantity = max(1, min((int) Config::get('security.max_order_qty', 10), $quantity));
        $product = Catalog::product($productId);
        if ($product === null || $product['status'] !== 'active') {
            return ['ok' => false, 'error' => 'This product is no longer available.'];
        }
        if ($product['stock'] <= 0) {
            return ['ok' => false, 'error' => 'This product is out of stock.'];
        }

        $current = self::quantityOf($productId);
        $target = min($product['stock'], $current + $quantity);
        if ($target <= 0) {
            return ['ok' => false, 'error' => 'This product is out of stock.'];
        }
        if ($target === $current) {
            return ['ok' => false, 'error' => 'Only ' . $product['stock'] . ' unit(s) available.'];
        }

        self::setQuantity($productId, $target);
        return ['ok' => true, 'quantity' => $target, 'count' => self::count(), 'message' => 'Added to cart.'];
    }

    public static function update(int $productId, int $quantity): array
    {
        if ($quantity <= 0) {
            self::remove($productId);
            return ['ok' => true, 'message' => 'Item removed.'];
        }
        $product = Catalog::product($productId);
        if ($product === null) {
            self::remove($productId);
            return ['ok' => false, 'error' => 'Product not found.'];
        }
        $quantity = min($quantity, $product['stock'], (int) Config::get('security.max_order_qty', 10));
        if ($quantity <= 0) {
            self::remove($productId);
            return ['ok' => false, 'error' => 'This product is out of stock.'];
        }
        self::setQuantity($productId, $quantity);
        return ['ok' => true, 'quantity' => $quantity, 'message' => 'Cart updated.'];
    }

    public static function remove(int $productId): void
    {
        if (Auth::check()) {
            Database::run('DELETE FROM cart_items WHERE user_id = :uid AND product_id = :pid', ['uid' => Auth::id(), 'pid' => $productId]);
            return;
        }
        unset($_SESSION[self::SESSION_KEY][$productId]);
    }

    public static function clear(): void
    {
        if (Auth::check()) {
            Database::run('DELETE FROM cart_items WHERE user_id = :uid', ['uid' => Auth::id()]);
            return;
        }
        unset($_SESSION[self::SESSION_KEY]);
    }

    /** Preserve the cart for a hosted payment attempt so a failure can be retried. */
    public static function rememberPendingCheckout(int $orderId, array $snapshot): void
    {
        $_SESSION['_gateway_cart_snapshots'][$orderId] = $snapshot;
    }

    /** Clear only the unchanged cart that produced a successfully paid order. */
    public static function clearPendingCheckoutIfUnchanged(int $orderId): bool
    {
        $snapshots = $_SESSION['_gateway_cart_snapshots'] ?? [];
        $snapshot = is_array($snapshots) ? ($snapshots[$orderId] ?? null) : null;
        if (!is_array($snapshot)) {
            return false;
        }
        $current = self::raw();
        ksort($snapshot);
        ksort($current);
        unset($_SESSION['_gateway_cart_snapshots'][$orderId]);
        if ($current !== $snapshot) {
            return false;
        }
        self::clear();
        return true;
    }

    /** Discard a saved cart comparison after a failed/abandoned payment attempt. */
    public static function forgetPendingCheckout(int $orderId): void
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return;
        }
        if (isset($_SESSION['_gateway_cart_snapshots'][$orderId])) {
            unset($_SESSION['_gateway_cart_snapshots'][$orderId]);
        }
        if (($_SESSION['_gateway_cart_snapshots'] ?? []) === []) {
            unset($_SESSION['_gateway_cart_snapshots']);
        }
    }

    public static function quantityOf(int $productId): int
    {
        return self::raw()[$productId] ?? 0;
    }

    private static function setQuantity(int $productId, int $quantity): void
    {
        if (Auth::check()) {
            Database::run(
                'INSERT INTO cart_items (user_id, product_id, quantity, created_at, updated_at)
                 VALUES (:uid, :pid, :qty, :now, :now2)
                 ON DUPLICATE KEY UPDATE quantity = :qty2, updated_at = :now3',
                [
                    'uid' => Auth::id(), 'pid' => $productId, 'qty' => $quantity,
                    'now' => date('Y-m-d H:i:s'), 'now2' => date('Y-m-d H:i:s'),
                    'qty2' => $quantity, 'now3' => date('Y-m-d H:i:s'),
                ]
            );
            return;
        }
        $_SESSION[self::SESSION_KEY][$productId] = $quantity;
    }

    /**
     * Hydrated cart lines with stock-aware quantities.
     *
     * @return array{items:list<array<string,mixed>>,unavailable:list<array{product_id:int,title:string,quantity:int,reason:string,product:?array}>,removed:list<string>,subtotal:float,count:int}
     */
    public static function contents(): array
    {
        $raw = self::raw();
        if ($raw === []) {
            return ['items' => [], 'unavailable' => [], 'removed' => [], 'subtotal' => 0.0, 'count' => 0];
        }
        $products = [];
        foreach (Catalog::productsByIds(array_keys($raw)) as $product) {
            $products[$product['id']] = $product;
        }

        $items = [];
        $unavailable = [];
        $removed = [];
        $subtotal = 0.0;
        $count = array_sum($raw);
        foreach ($raw as $productId => $qty) {
            $product = $products[$productId] ?? null;
            if ($product === null || $product['status'] !== 'active') {
                $unavailable[] = [
                    'product_id' => (int) $productId,
                    'title' => $product['title'] ?? 'Product no longer available',
                    'quantity' => (int) $qty,
                    'reason' => 'This product is no longer available for purchase.',
                    'product' => $product,
                ];
                continue;
            }
            if ((int) $product['stock'] < $qty) {
                $available = max(0, (int) $product['stock']);
                $unavailable[] = [
                    'product_id' => (int) $productId,
                    'title' => (string) $product['title'],
                    'quantity' => (int) $qty,
                    'reason' => $available === 0
                        ? 'Out of stock; your cart has ' . (int) $qty . ' unit(s) selected. Remove this item or try again later.'
                        : 'Your cart has ' . (int) $qty . ' unit(s) selected, but only ' . $available . ' are available. Update the quantity or remove this item.',
                    'product' => $product,
                ];
                continue;
            }
            $quantity = (int) $qty;
            $lineTotal = $product['price'] * $quantity;
            $subtotal += $lineTotal;
            $items[] = [
                'product' => $product,
                'quantity' => $quantity,
                'line_total' => $lineTotal,
                'free_shipping' => (int) $product['free_shipping'] === 1,
            ];
        }

        return ['items' => $items, 'unavailable' => $unavailable, 'removed' => $removed, 'subtotal' => round($subtotal, 2), 'count' => $count];
    }

    /** Move a guest basket into the signed-in user's cart. */
    public static function mergeSessionIntoDatabase(): void
    {
        if (!Auth::check()) {
            return;
        }
        $session = $_SESSION[self::SESSION_KEY] ?? [];
        if (!is_array($session) || $session === []) {
            return;
        }
        foreach ($session as $productId => $qty) {
            $product = Catalog::product((int) $productId);
            if ($product === null || $product['stock'] <= 0) {
                continue;
            }
            $existing = Database::int(
                'SELECT quantity FROM cart_items WHERE user_id = :uid AND product_id = :pid',
                ['uid' => Auth::id(), 'pid' => (int) $productId]
            );
            $quantity = min($product['stock'], max(1, $existing + (int) $qty), (int) Config::get('security.max_order_qty', 10));
            Database::run(
                'INSERT INTO cart_items (user_id, product_id, quantity, created_at, updated_at)
                 VALUES (:uid, :pid, :qty, :now, :now2)
                 ON DUPLICATE KEY UPDATE quantity = :qty2, updated_at = :now3',
                [
                    'uid' => Auth::id(), 'pid' => (int) $productId, 'qty' => $quantity,
                    'now' => date('Y-m-d H:i:s'), 'now2' => date('Y-m-d H:i:s'),
                    'qty2' => $quantity, 'now3' => date('Y-m-d H:i:s'),
                ]
            );
        }
        unset($_SESSION[self::SESSION_KEY]);
    }

    /** @return list<int> */
    public static function productIds(): array
    {
        return array_keys(self::raw());
    }

    /** @return list<array<string,mixed>> wishlist product ids for the current user */
    public static function wishlistIds(): array
    {
        if (!Auth::check()) {
            return [];
        }
        $rows = Database::all('SELECT product_id FROM wishlist_items WHERE user_id = :uid', ['uid' => Auth::id()]);
        return array_map(static fn ($r) => (int) $r['product_id'], $rows);
    }

    public static function toggleWishlist(int $productId): array
    {
        if (!Auth::check()) {
            return ['ok' => false, 'error' => 'Sign in to save favourites.', 'login' => true];
        }
        $exists = Database::exists(
            'SELECT 1 FROM wishlist_items WHERE user_id = :uid AND product_id = :pid LIMIT 1',
            ['uid' => Auth::id(), 'pid' => $productId]
        );
        if ($exists) {
            Database::run('DELETE FROM wishlist_items WHERE user_id = :uid AND product_id = :pid', ['uid' => Auth::id(), 'pid' => $productId]);
            return ['ok' => true, 'saved' => false, 'message' => 'Removed from wishlist.'];
        }
        Database::run(
            'INSERT INTO wishlist_items (user_id, product_id, created_at) VALUES (:uid, :pid, :now)',
            ['uid' => Auth::id(), 'pid' => $productId, 'now' => date('Y-m-d H:i:s')]
        );
        return ['ok' => true, 'saved' => true, 'message' => 'Saved to wishlist.'];
    }
}

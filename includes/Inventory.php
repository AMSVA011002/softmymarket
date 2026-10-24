<?php
declare(strict_types=1);

/**
 * Stock adjustments with a full movement history.
 *
 * All stock changes go through adjust(), which locks the product row, refuses
 * to push stock below zero and writes an inventory_movements row in the same
 * transaction.
 */
final class Inventory
{
    public static function adjust(int $productId, int $delta, string $reason, ?string $reference = null, ?string $note = null): int
    {
        return Database::tx(static function () use ($productId, $delta, $reason, $reference, $note): int {
            $row = Database::one('SELECT stock FROM products WHERE id = :id FOR UPDATE', ['id' => $productId]);
            if ($row === null) {
                throw new RuntimeException('Product not found.');
            }
            $current = (int) $row['stock'];
            $next = $current + $delta;
            if ($next < 0) {
                throw new RuntimeException('Only ' . $current . ' unit(s) available.');
            }
            Database::run('UPDATE products SET stock = :stock, updated_at = :now WHERE id = :id', [
                'stock' => $next, 'now' => date('Y-m-d H:i:s'), 'id' => $productId,
            ]);
            self::record($productId, $delta, $reason, $note, $reference);
            return $next;
        });
    }

    public static function setStock(int $productId, int $newStock, string $reason = 'count', ?string $note = null): int
    {
        $current = Database::int('SELECT stock FROM products WHERE id = :id', ['id' => $productId]);
        return self::adjust($productId, $newStock - $current, $reason, null, $note);
    }

    /** Write a movement row (used directly by the product import/seeder). */
    public static function record(int $productId, int $change, string $reason, ?string $note = null, ?string $reference = null): void
    {
        try {
            Database::insert('inventory_movements', [
                'product_id' => $productId,
                'change_qty' => $change,
                'reason'     => substr($reason, 0, 40),
                'reference'  => $reference !== null ? substr($reference, 0, 80) : null,
                'note'       => $note,
                'admin_id'   => Auth::id(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            error_log('Inventory movement failed: ' . $e->getMessage());
        }
    }

    /** @return list<array<string,mixed>> */
    public static function movements(int $productId, int $limit = 30): array
    {
        return Database::all(
            'SELECT m.*, u.name AS admin_name
               FROM inventory_movements m
          LEFT JOIN users u ON u.id = m.admin_id
              WHERE m.product_id = :pid
           ORDER BY m.id DESC LIMIT ' . max(1, min(200, $limit)),
            ['pid' => $productId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function recent(int $limit = 20): array
    {
        return Database::all(
            'SELECT m.*, p.title AS product_title, p.sku, u.name AS admin_name
               FROM inventory_movements m
               JOIN products p ON p.id = m.product_id
          LEFT JOIN users u ON u.id = m.admin_id
           ORDER BY m.id DESC LIMIT ' . max(1, min(200, $limit))
        );
    }

    public static function stockValue(): float
    {
        return Database::float('SELECT COALESCE(SUM(stock * COALESCE(cost_price, price)), 0) FROM products WHERE deleted_at IS NULL');
    }

    public static function lowStockCount(): int
    {
        return Database::int('SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND stock <= low_stock_alert AND status = :s', ['s' => 'active']);
    }

    public static function outOfStockCount(): int
    {
        return Database::int("SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND stock <= 0 AND status = 'active'");
    }

    public static function totalUnits(): int
    {
        return Database::int('SELECT COALESCE(SUM(stock), 0) FROM products WHERE deleted_at IS NULL');
    }
}

<?php
declare(strict_types=1);

/**
 * Read-only analytics used by the dashboard and the reports screens.
 *
 * Every aggregation accepts an inclusive date range (Y-m-d) and returns plain
 * arrays so the same data can be rendered as cards, tables or CSV.
 */
final class Reports
{
    private static function range(?string $from, ?string $to): array
    {
        $to = $to !== null && $to !== '' ? $to : date('Y-m-d');
        $from = $from !== null && $from !== '' ? $from : date('Y-m-d', strtotime($to . ' -29 days'));
        if (strtotime($from) > strtotime($to)) {
            [$from, $to] = [$to, $from];
        }
        return [$from . ' 00:00:00', $to . ' 23:59:59', $from, $to];
    }

    /** Headline numbers for the selected period. */
    public static function summary(?string $from = null, ?string $to = null): array
    {
        [$start, $end, $fromDate, $toDate] = self::range($from, $to);
        $params = ['start' => $start, 'end' => $end];

        $sales = Database::one(
            "SELECT COUNT(*) AS orders,
                    COALESCE(SUM(total), 0) AS revenue,
                    COALESCE(SUM(subtotal), 0) AS gross,
                    COALESCE(SUM(discount), 0) AS discounts,
                    COALESCE(SUM(shipping_fee), 0) AS shipping,
                    COALESCE(AVG(total), 0) AS aov
               FROM orders
              WHERE placed_at BETWEEN :start AND :end AND status <> 'CANCELLED'",
            $params
        ) ?? [];

        $previousStart = date('Y-m-d 00:00:00', strtotime($fromDate . ' -' . (int) ((strtotime($toDate) - strtotime($fromDate)) / 86400 + 1) . ' days'));
        $previousEnd = date('Y-m-d 23:59:59', strtotime($fromDate . ' -1 day'));
        $previous = Database::one(
            "SELECT COUNT(*) AS orders, COALESCE(SUM(total), 0) AS revenue
               FROM orders
              WHERE placed_at BETWEEN :ps AND :pe AND status <> 'CANCELLED'",
            ['ps' => $previousStart, 'pe' => $previousEnd]
        ) ?? ['orders' => 0, 'revenue' => 0];

        $customers = Database::int(
            'SELECT COUNT(DISTINCT COALESCE(user_id, email)) FROM orders WHERE placed_at BETWEEN :start AND :end2',
            ['start' => $start, 'end2' => $end]
        );
        $newCustomers = Database::int(
            'SELECT COUNT(*) FROM users WHERE role = :role AND created_at BETWEEN :start3 AND :end3',
            ['role' => 'customer', 'start3' => $start, 'end3' => $end]
        );
        $collected = Database::float(
            "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'paid' AND created_at BETWEEN :s AND :e",
            ['s' => $start, 'e' => $end]
        );
        $refunded = Database::float(
            "SELECT COALESCE(SUM(total), 0) FROM orders WHERE status = 'REFUNDED' AND updated_at BETWEEN :s2 AND :e2",
            ['s2' => $start, 'e2' => $end]
        );

        $revenue = (float) ($sales['revenue'] ?? 0);
        $previousRevenue = (float) ($previous['revenue'] ?? 0);
        $growth = $previousRevenue > 0 ? (($revenue - $previousRevenue) / $previousRevenue) * 100 : null;

        return [
            'from' => $fromDate,
            'to' => $toDate,
            'orders' => (int) ($sales['orders'] ?? 0),
            'revenue' => $revenue,
            'gross' => (float) ($sales['gross'] ?? 0),
            'discounts' => (float) ($sales['discounts'] ?? 0),
            'shipping' => (float) ($sales['shipping'] ?? 0),
            'aov' => (float) ($sales['aov'] ?? 0),
            'customers' => $customers,
            'new_customers' => $newCustomers,
            'collected' => $collected,
            'outstanding' => max(0.0, $revenue - $collected),
            'refunded' => $refunded,
            'previous_orders' => (int) ($previous['orders'] ?? 0),
            'previous_revenue' => $previousRevenue,
            'growth' => $growth,
        ];
    }

    /** Revenue per day (fills gaps with zeroes so charts stay honest). */
    public static function daily(?string $from = null, ?string $to = null): array
    {
        [$start, $end, $fromDate, $toDate] = self::range($from, $to);
        $rows = Database::all(
            "SELECT DATE(placed_at) AS d, COUNT(*) AS orders, COALESCE(SUM(total), 0) AS revenue
               FROM orders
              WHERE placed_at BETWEEN :start AND :end AND status <> 'CANCELLED'
           GROUP BY DATE(placed_at)
           ORDER BY d ASC",
            ['start' => $start, 'end' => $end]
        );
        $byDate = [];
        foreach ($rows as $row) {
            $byDate[(string) $row['d']] = $row;
        }
        $series = [];
        $cursor = strtotime($fromDate);
        $last = strtotime($toDate);
        while ($cursor !== false && $cursor <= $last) {
            $key = date('Y-m-d', $cursor);
            $series[] = [
                'date' => $key,
                'label' => date('j M', $cursor),
                'orders' => (int) ($byDate[$key]['orders'] ?? 0),
                'revenue' => (float) ($byDate[$key]['revenue'] ?? 0),
            ];
            $cursor = strtotime('+1 day', $cursor);
        }
        return $series;
    }

    public static function byCategory(?string $from = null, ?string $to = null, int $limit = 10): array
    {
        [$start, $end] = self::range($from, $to);
        return Database::all(
            "SELECT c.id, c.name, COALESCE(SUM(oi.total), 0) AS revenue, COALESCE(SUM(oi.quantity), 0) AS units
               FROM order_items oi
               JOIN orders o ON o.id = oi.order_id
          LEFT JOIN products p ON p.id = oi.product_id
          LEFT JOIN categories c ON c.id = p.category_id
              WHERE o.placed_at BETWEEN :start AND :end AND o.status <> 'CANCELLED'
           GROUP BY c.id, c.name
           ORDER BY revenue DESC
              LIMIT " . max(1, min(50, $limit)),
            ['start' => $start, 'end' => $end]
        );
    }

    public static function byPaymentMethod(?string $from = null, ?string $to = null): array
    {
        [$start, $end] = self::range($from, $to);
        return Database::all(
            "SELECT payment_method, COUNT(*) AS orders, COALESCE(SUM(total), 0) AS revenue
               FROM orders
              WHERE placed_at BETWEEN :start AND :end AND status <> 'CANCELLED'
           GROUP BY payment_method
           ORDER BY revenue DESC",
            ['start' => $start, 'end' => $end]
        );
    }

    public static function byStatus(?string $from = null, ?string $to = null): array
    {
        [$start, $end] = self::range($from, $to);
        return Database::all(
            'SELECT status, COUNT(*) AS orders, COALESCE(SUM(total), 0) AS revenue
               FROM orders
              WHERE placed_at BETWEEN :start AND :end
           GROUP BY status
           ORDER BY orders DESC',
            ['start' => $start, 'end' => $end]
        );
    }

    public static function topCustomers(?string $from = null, ?string $to = null, int $limit = 10): array
    {
        [$start, $end] = self::range($from, $to);
        return Database::all(
            // One row per customer. The display name is aggregated so the query
            // is valid under ONLY_FULL_GROUP_BY (and strict SQL engines), and
            // grouping never depends on how a dialect resolves aliases.
            "SELECT MAX(COALESCE(u.name, o.customer_name)) AS name, o.email, o.user_id,
                    COUNT(*) AS orders, COALESCE(SUM(o.total), 0) AS spent
               FROM orders o
          LEFT JOIN users u ON u.id = o.user_id
              WHERE o.placed_at BETWEEN :start AND :end AND o.status <> 'CANCELLED'
           GROUP BY o.email, o.user_id
           ORDER BY spent DESC
              LIMIT " . max(1, min(50, $limit)),
            ['start' => $start, 'end' => $end]
        );
    }

    public static function topProducts(?string $from = null, ?string $to = null, int $limit = 10): array
    {
        [$start, $end] = self::range($from, $to);
        return Database::all(
            "SELECT oi.product_id, oi.title, oi.sku, SUM(oi.quantity) AS units, SUM(oi.total) AS revenue
               FROM order_items oi
               JOIN orders o ON o.id = oi.order_id
              WHERE o.placed_at BETWEEN :start AND :end AND o.status <> 'CANCELLED'
           GROUP BY oi.product_id, oi.title, oi.sku
           ORDER BY revenue DESC
              LIMIT " . max(1, min(50, $limit)),
            ['start' => $start, 'end' => $end]
        );
    }

    public static function couponPerformance(?string $from = null, ?string $to = null, int $limit = 12): array
    {
        [$start, $end] = self::range($from, $to);
        return Database::all(
            "SELECT COALESCE(c.code, o.coupon_code) AS code, COUNT(*) AS uses, COALESCE(SUM(o.discount), 0) AS given
               FROM orders o
          LEFT JOIN coupons c ON c.id = o.coupon_id
              WHERE o.placed_at BETWEEN :start AND :end
                AND (o.coupon_id IS NOT NULL OR o.coupon_code IS NOT NULL)
           GROUP BY c.code, o.coupon_code
           ORDER BY uses DESC
              LIMIT " . max(1, min(50, $limit)),
            ['start' => $start, 'end' => $end]
        );
    }

    /** Stock value at cost and at retail, plus low/out-of-stock counts. */
    public static function inventory(): array
    {
        $row = Database::one(
            "SELECT COUNT(*) AS skus,
                    COALESCE(SUM(stock), 0) AS units,
                    COALESCE(SUM(stock * COALESCE(cost_price, price * 0.7)), 0) AS cost_value,
                    COALESCE(SUM(stock * price), 0) AS retail_value,
                    SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END) AS out_of_stock,
                    SUM(CASE WHEN stock > 0 AND stock <= low_stock_alert THEN 1 ELSE 0 END) AS low_stock
               FROM products
              WHERE deleted_at IS NULL"
        ) ?? [];
        return [
            'skus' => (int) ($row['skus'] ?? 0),
            'units' => (int) ($row['units'] ?? 0),
            'cost_value' => (float) ($row['cost_value'] ?? 0),
            'retail_value' => (float) ($row['retail_value'] ?? 0),
            'out_of_stock' => (int) ($row['out_of_stock'] ?? 0),
            'low_stock' => (int) ($row['low_stock'] ?? 0),
        ];
    }

    /** Stream a CSV download of the sales rows in a range. */
    public static function salesCsv(?string $from = null, ?string $to = null): never
    {
        [$start, $end, $fromDate, $toDate] = self::range($from, $to);
        $rows = Database::all(
            'SELECT order_number, placed_at, customer_name, email, phone, status, payment_method,
                    payment_status, subtotal, shipping_fee, discount, total, currency
               FROM orders
              WHERE placed_at BETWEEN :start AND :end
           ORDER BY placed_at DESC',
            ['start' => $start, 'end' => $end]
        );

        $filename = 'sales-' . $fromDate . '-to-' . $toDate . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'wb');
        if ($out === false) {
            exit;
        }
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens it correctly
        fputcsv($out, ['Order', 'Placed', 'Customer', 'Email', 'Phone', 'Status', 'Payment method', 'Payment status', 'Subtotal', 'Shipping', 'Discount', 'Total', 'Currency']);
        foreach ($rows as $row) {
            fputcsv($out, [
                csv_line([(string) $row['order_number']]),
                (string) $row['placed_at'],
                (string) $row['customer_name'],
                (string) $row['email'],
                (string) $row['phone'],
                (string) $row['status'],
                payment_method_label((string) $row['payment_method']),
                (string) $row['payment_status'],
                number_format((float) $row['subtotal'], 2, '.', ''),
                number_format((float) $row['shipping_fee'], 2, '.', ''),
                number_format((float) $row['discount'], 2, '.', ''),
                number_format((float) $row['total'], 2, '.', ''),
                (string) $row['currency'],
            ]);
        }
        fclose($out);
        exit;
    }
}

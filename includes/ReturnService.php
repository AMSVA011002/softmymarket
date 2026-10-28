<?php
declare(strict_types=1);

/**
 * Customer return authorisation (RMA) lifecycle. Monetary refunds are recorded
 * for reconciliation only; this service never initiates a bank/card refund.
 */
final class ReturnService
{
    public const REASONS = [
        'DAMAGED' => 'Arrived damaged',
        'DEFECTIVE' => 'Faulty / not working',
        'WRONG_ITEM' => 'Wrong item received',
        'NOT_AS_DESCRIBED' => 'Not as described',
        'CHANGED_MIND' => 'Changed my mind',
        'OTHER' => 'Other',
    ];

    /** Pro-rata merchandise discount/tax allocation, less prior refunds and shipping. */
    public static function calculateRefundCap(float $itemGross, float $subtotal, float $discount, float $tax, float $orderTotal, float $priorRefunds): float
    {
        $itemGross = max(0.0, $itemGross);
        $subtotal = max(0.0, $subtotal);
        $discount = min($subtotal, max(0.0, $discount));
        $tax = max(0.0, $tax);
        $discountShare = $subtotal > 0 ? $discount * ($itemGross / $subtotal) : 0.0;
        $netItemValue = max(0.0, $itemGross - $discountShare);
        $taxBase = max(0.0, $subtotal - $discount);
        $taxShare = $taxBase > 0 ? $tax * ($netItemValue / $taxBase) : 0.0;
        return round(min($netItemValue + $taxShare, max(0.0, $orderTotal - $priorRefunds)), 2);
    }

    public const TRANSITIONS = [
        'REQUESTED' => ['APPROVED', 'REJECTED', 'CANCELLED'],
        'APPROVED' => ['IN_TRANSIT', 'RECEIVED', 'CANCELLED'],
        'IN_TRANSIT' => ['RECEIVED', 'CANCELLED'],
        'RECEIVED' => ['INSPECTING', 'REFUNDED', 'EXCHANGED', 'REJECTED', 'CLOSED'],
        'INSPECTING' => ['REFUNDED', 'EXCHANGED', 'REJECTED', 'CLOSED'],
        'REJECTED' => [],
        'REFUNDED' => [],
        'EXCHANGED' => [],
        'CLOSED' => [],
        'CANCELLED' => [],
    ];

    /** @return list<array<string,mixed>> */
    public static function eligibleOrders(int $userId): array
    {
        $rows = Database::all(
            "SELECT o.*, COALESCE(o.delivered_at, (SELECT MAX(oe.created_at) FROM order_events oe WHERE oe.order_id = o.id AND oe.status = 'DELIVERED')) AS delivered_on
               FROM orders o
              WHERE o.user_id = :uid
                AND o.status = 'DELIVERED'
                AND COALESCE(o.delivered_at, (SELECT MAX(oe.created_at) FROM order_events oe WHERE oe.order_id = o.id AND oe.status = 'DELIVERED')) >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                AND EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND oi.quantity > COALESCE((
                    SELECT SUM(ri.quantity) FROM return_request_items ri
                    JOIN return_requests rr ON rr.id = ri.return_request_id
                    WHERE ri.order_item_id = oi.id AND rr.status NOT IN ('REJECTED','CANCELLED')
                ), 0))
              ORDER BY o.delivered_at DESC, o.id DESC",
            ['uid' => $userId]
        );
        return array_map([Orders::class, 'map'], $rows);
    }

    /** @return list<array<string,mixed>> */
    public static function eligibleItems(int $orderId): array
    {
        return Database::all(
            "SELECT oi.*, GREATEST(0, oi.quantity - COALESCE(used.returned_quantity, 0)) AS returnable_quantity
               FROM order_items oi
          LEFT JOIN (
                SELECT ri.order_item_id, SUM(ri.quantity) AS returned_quantity
                  FROM return_request_items ri
                  JOIN return_requests rr ON rr.id = ri.return_request_id
                 WHERE rr.status NOT IN ('REJECTED','CANCELLED')
              GROUP BY ri.order_item_id
          ) used ON used.order_item_id = oi.id
              WHERE oi.order_id = :oid AND oi.quantity > COALESCE(used.returned_quantity, 0)
           ORDER BY oi.id",
            ['oid' => $orderId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function forUser(int $userId): array
    {
        return Database::all(
            'SELECT rr.*, o.order_number,
                    (SELECT COUNT(*) FROM return_request_items ri WHERE ri.return_request_id = rr.id) AS line_count
               FROM return_requests rr JOIN orders o ON o.id = rr.order_id
              WHERE rr.user_id = :uid ORDER BY rr.created_at DESC, rr.id DESC',
            ['uid' => $userId]
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::one(
            'SELECT rr.*, o.order_number, o.currency, o.total AS order_total, o.payment_method,
                    o.status AS order_status, o.delivered_at, o.user_id AS order_user_id,
                    o.subtotal AS order_subtotal, o.discount AS order_discount, o.tax AS order_tax,
                    o.customer_name, o.email
               FROM return_requests rr JOIN orders o ON o.id = rr.order_id
              WHERE rr.id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    public static function ownedBy(array $request, int $userId): bool
    {
        return (int) ($request['user_id'] ?? 0) === $userId;
    }

    /** @return list<array<string,mixed>> */
    public static function items(int $returnId): array
    {
        return Database::all(
            'SELECT ri.*, oi.image_url, oi.product_id
               FROM return_request_items ri
               JOIN order_items oi ON oi.id = ri.order_item_id
              WHERE ri.return_request_id = :id ORDER BY ri.id',
            ['id' => $returnId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function events(int $returnId): array
    {
        return Database::all('SELECT * FROM return_events WHERE return_request_id = :id ORDER BY id', ['id' => $returnId]);
    }

    /**
     * Create one request for selected order lines. The order row lock serialises
     * concurrent requests so two browser submissions cannot over-claim units.
     * @param array<int,int> $quantities order_item_id => requested quantity
     * @return array{ok:bool,id?:int,number?:string,error?:string}
     */
    public static function create(int $userId, int $orderId, array $quantities, string $reason, string $details, ?string $evidenceFile = null, ?string $evidenceMime = null): array
    {
        if (!isset(self::REASONS[$reason])) {
            return ['ok' => false, 'error' => 'Choose a valid reason for the return.'];
        }
        $details = trim($details);
        if (strlen($details) < 10 || strlen($details) > 2000) {
            return ['ok' => false, 'error' => 'Please describe the issue in 10–2,000 characters.'];
        }
        $quantities = array_filter($quantities, static fn ($q) => (int) $q > 0);
        if ($quantities === [] || count($quantities) > 20) {
            return ['ok' => false, 'error' => 'Select at least one item (up to 20 lines) to return.'];
        }

        try {
            $id = Database::tx(static function () use ($userId, $orderId, $quantities, $reason, $details, $evidenceFile, $evidenceMime): int {
                $order = Database::one('SELECT * FROM orders WHERE id = :id AND user_id = :uid FOR UPDATE', ['id' => $orderId, 'uid' => $userId]);
                if ($order === null) {
                    throw new RuntimeException('That order was not found in your account.');
                }
                if ((string) $order['status'] !== 'DELIVERED') {
                    throw new RuntimeException('Returns can only be requested for delivered orders.');
                }
                $deliveredAt = (string) ($order['delivered_at'] ?? '');
                if ($deliveredAt === '') {
                    $event = Database::one("SELECT created_at FROM order_events WHERE order_id = :id AND status = 'DELIVERED' ORDER BY id DESC LIMIT 1", ['id' => $orderId]);
                    $deliveredAt = (string) ($event['created_at'] ?? '');
                }
                $deliveryTime = $deliveredAt !== '' ? strtotime($deliveredAt) : false;
                if ($deliveryTime === false || $deliveryTime < time() - 7 * 86400) {
                    throw new RuntimeException('The return window is 7 days from delivery and has expired.');
                }
                if (in_array($reason, ['DAMAGED', 'WRONG_ITEM'], true)) {
                    if ($deliveryTime < time() - 48 * 3600) {
                        throw new RuntimeException('Damaged or wrong-item reports must be submitted within 48 hours of delivery. Contact support if you need help.');
                    }
                    if (!str_starts_with((string) $evidenceMime, 'image/')) {
                        throw new RuntimeException('Please attach a photo for a damaged or wrong-item report.');
                    }
                }

                $requestId = Database::insert('return_requests', [
                    'order_id' => $orderId,
                    'user_id' => $userId,
                    'return_number' => 'TMP-' . strtoupper(bin2hex(random_bytes(6))),
                    'status' => 'REQUESTED',
                    'reason' => $reason,
                    'customer_details' => $details,
                    'evidence_file' => $evidenceFile,
                    'evidence_mime' => $evidenceMime,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

                foreach ($quantities as $rawItemId => $rawQuantity) {
                    $itemId = filter_var($rawItemId, FILTER_VALIDATE_INT);
                    $quantity = filter_var($rawQuantity, FILTER_VALIDATE_INT);
                    if (!$itemId || !$quantity || $quantity < 1) {
                        throw new RuntimeException('One of the selected quantities is invalid.');
                    }
                    $item = Database::one(
                        'SELECT oi.id, oi.order_id, oi.title, oi.sku, oi.unit_price, oi.quantity,
                                COALESCE((SELECT SUM(ri.quantity) FROM return_request_items ri
                                    JOIN return_requests rr ON rr.id = ri.return_request_id
                                   WHERE ri.order_item_id = oi.id AND rr.status NOT IN (\'REJECTED\',\'CANCELLED\')), 0) AS already_requested
                           FROM order_items oi WHERE oi.id = :iid AND oi.order_id = :oid FOR UPDATE',
                        ['iid' => (int) $itemId, 'oid' => $orderId]
                    );
                    if ($item === null || $quantity > (int) $item['quantity'] - (int) $item['already_requested']) {
                        throw new RuntimeException('A selected item or quantity is no longer eligible. Refresh the page and try again.');
                    }
                    Database::insert('return_request_items', [
                        'return_request_id' => $requestId,
                        'order_item_id' => (int) $item['id'],
                        'item_title' => (string) $item['title'],
                        'sku' => $item['sku'],
                        'unit_price' => (float) $item['unit_price'],
                        'quantity' => $quantity,
                    ]);
                }

                $number = 'RET-' . date('Y') . '-' . str_pad((string) $requestId, 6, '0', STR_PAD_LEFT);
                Database::run('UPDATE return_requests SET return_number = :n WHERE id = :id', ['n' => $number, 'id' => $requestId]);
                self::addEvent($requestId, 'REQUESTED', 'Return request submitted and awaiting review.', 'customer', $userId);
                return $requestId;
            });
        } catch (Throwable $e) {
            error_log('Return request creation failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage() ?: 'We could not submit your return request. Please try again.'];
        }

        $request = self::find($id);
        if ($request !== null) {
            Notifier::notifyTeam('Return request ' . $request['return_number'], 'New request for order ' . $request['order_number'] . '.', 'order', '/admin/return.php?id=' . $id);
            Audit::log('return.requested', 'return', $id, ['order_id' => $orderId, 'reason' => $reason]);
        }
        return ['ok' => true, 'id' => $id, 'number' => (string) ($request['return_number'] ?? '')];
    }

    /** @return array{ok:bool,message:string} */
    public static function transition(int $id, string $next, string $message, string $actorType, ?int $actorId): array
    {
        $next = strtoupper($next);
        $message = trim($message);
        if (strlen($message) > 500) {
            return ['ok' => false, 'message' => 'The update note must be 500 characters or fewer.'];
        }
        try {
            $request = Database::tx(static function () use ($id, $next, $message, $actorType, $actorId): array {
                $request = Database::one('SELECT * FROM return_requests WHERE id = :id FOR UPDATE', ['id' => $id]);
                if ($request === null) {
                    throw new RuntimeException('Return request not found.');
                }
                $allowed = self::TRANSITIONS[(string) $request['status']] ?? [];
                if (!in_array($next, $allowed, true)) {
                    throw new RuntimeException('That return status change is not allowed.');
                }
                $data = ['status' => $next, 'updated_at' => date('Y-m-d H:i:s')];
                if (in_array($next, ['APPROVED', 'REJECTED'], true)) {
                    $data['reviewed_by'] = $actorId;
                    $data['reviewed_at'] = date('Y-m-d H:i:s');
                }
                Database::update('return_requests', $data, 'id = :id', ['id' => $id]);
                $eventMessage = $message !== '' ? $message : self::defaultMessage($next);
                self::addEvent($id, $next, $eventMessage, $actorType, $actorId);
                return $request;
            });
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage() ?: 'Could not update return request.'];
        }
        self::notifyStatus($request, $next);
        Audit::log('return.status_changed', 'return', $id, ['from' => $request['status'], 'to' => $next]);
        return ['ok' => true, 'message' => 'Return request updated.'];
    }

    /** Customer enters return-shipping details after approval. */
    public static function submitTracking(int $id, int $userId, string $carrier, string $tracking): array
    {
        $carrier = trim($carrier);
        $tracking = trim($tracking);
        if ($carrier === '' || strlen($carrier) > 80 || $tracking === '' || strlen($tracking) > 120) {
            return ['ok' => false, 'message' => 'Enter a carrier and a tracking number (up to 120 characters).'];
        }
        $request = self::find($id);
        if ($request === null || !self::ownedBy($request, $userId)) {
            return ['ok' => false, 'message' => 'Return request not found.'];
        }
        if ($request['status'] !== 'APPROVED') {
            return ['ok' => false, 'message' => 'Return shipping details can only be added to an approved request.'];
        }
        $result = self::transition($id, 'IN_TRANSIT', 'Return parcel sent via ' . $carrier . ' (tracking ' . $tracking . ').', 'customer', $userId);
        if (!$result['ok']) {
            return $result;
        }
        Database::update('return_requests', ['return_carrier' => $carrier, 'return_tracking' => $tracking, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id AND user_id = :uid', ['id' => $id, 'uid' => $userId]);
        return $result;
    }

    /** Finalise and record a manually processed refund; no payment API is called. */
    public static function refund(int $id, float $amount, string $method, string $reference, bool $restock, string $message, int $actorId): array
    {
        $amount = round($amount, 2);
        $method = trim($method);
        $reference = trim($reference);
        if ($amount <= 0 || $method === '' || strlen($method) > 40 || strlen($reference) > 120) {
            return ['ok' => false, 'message' => 'Enter a positive refund amount and refund method; reference is optional.'];
        }
        try {
            $request = Database::tx(static function () use ($id, $amount, $method, $reference, $restock, $message, $actorId): array {
                $request = Database::one('SELECT * FROM return_requests WHERE id = :id FOR UPDATE', ['id' => $id]);
                if ($request === null) {
                    throw new RuntimeException('Return request not found.');
                }
                if ((string) $request['status'] !== 'INSPECTING') {
                    throw new RuntimeException('A return must be received and marked as under inspection before it can be refunded.');
                }
                // Lock the order before summing prior refunds. This serialises
                // refunds for separate return requests on the same order.
                $order = Database::one('SELECT total, subtotal, discount, tax FROM orders WHERE id = :id FOR UPDATE', ['id' => (int) $request['order_id']]);
                $itemValue = Database::float('SELECT COALESCE(SUM(unit_price * quantity), 0) FROM return_request_items WHERE return_request_id = :id', ['id' => $id]);
                $priorRefunds = Database::float("SELECT COALESCE(SUM(refund_amount),0) FROM return_requests WHERE order_id = :oid AND status = 'REFUNDED' AND id <> :id", ['oid' => (int) $request['order_id'], 'id' => $id]);
                $max = self::calculateRefundCap($itemValue, (float) ($order['subtotal'] ?? 0), (float) ($order['discount'] ?? 0), (float) ($order['tax'] ?? 0), (float) ($order['total'] ?? 0), $priorRefunds);
                if ($amount > $max + 0.001) {
                    throw new RuntimeException('Refund cannot exceed the remaining eligible amount of ' . money($max) . '.');
                }
                self::restockItems($request, $restock);
                Database::update('return_requests', [
                    'status' => 'REFUNDED', 'refund_amount' => round($amount, 2), 'refund_method' => $method,
                    'refund_reference' => $reference !== '' ? $reference : null,
                    'restocked_at' => $restock ? date('Y-m-d H:i:s') : null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id = :id', ['id' => $id]);
                $note = trim($message);
                $event = 'Refund recorded: ' . money($amount) . ' via ' . $method . ($reference !== '' ? ' (reference ' . $reference . ')' : '') . ($note !== '' ? '. ' . $note : '.');
                self::addEvent($id, 'REFUNDED', $event, 'staff', $actorId);
                $totalRefunded = Database::float("SELECT COALESCE(SUM(refund_amount),0) FROM return_requests WHERE order_id = :oid AND status = 'REFUNDED'", ['oid' => (int) $request['order_id']]);
                $order = Database::one('SELECT total FROM orders WHERE id = :id FOR UPDATE', ['id' => (int) $request['order_id']]);
                if ($order !== null) {
                    $paymentStatus = $totalRefunded + 0.001 >= (float) $order['total'] ? 'REFUNDED' : 'PARTIAL';
                    Database::update('orders', ['payment_status' => $paymentStatus], 'id = :id', ['id' => (int) $request['order_id']]);
                }
                return $request;
            });
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage() ?: 'Could not record the refund.'];
        }
        self::notifyStatus($request, 'REFUNDED');
        Audit::log('return.refunded', 'return', $id, ['amount' => round($amount, 2), 'method' => $method, 'restocked' => $restock]);
        return ['ok' => true, 'message' => 'Refund recorded. Ensure the money has actually been returned through the stated method.'];
    }

    /** Complete an exchange outcome and optionally return sellable stock. */
    public static function exchange(int $id, bool $restock, string $message, int $actorId): array
    {
        try {
            $request = Database::tx(static function () use ($id, $restock, $message, $actorId): array {
                $request = Database::one('SELECT * FROM return_requests WHERE id = :id FOR UPDATE', ['id' => $id]);
                if ($request === null || (string) $request['status'] !== 'INSPECTING') {
                    throw new RuntimeException('A return must be received and marked as under inspection before it can be exchanged.');
                }
                self::restockItems($request, $restock);
                Database::update('return_requests', [
                    'status' => 'EXCHANGED', 'restocked_at' => $restock ? date('Y-m-d H:i:s') : null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id = :id', ['id' => $id]);
                self::addEvent($id, 'EXCHANGED', trim($message) !== '' ? trim($message) : 'Exchange completed by the store.', 'staff', $actorId);
                return $request;
            });
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage() ?: 'Could not record the exchange.'];
        }
        self::notifyStatus($request, 'EXCHANGED');
        Audit::log('return.exchanged', 'return', $id, ['restocked' => $restock]);
        return ['ok' => true, 'message' => 'Exchange recorded. Arrange and record replacement fulfilment separately.'];
    }

    /** @return array<string,mixed> */
    public static function adminList(string $status = '', string $query = '', int $page = 1, int $per = 25): array
    {
        $where = ['1=1'];
        $params = [];
        if ($status !== '' && isset(self::TRANSITIONS[$status])) {
            $where[] = 'rr.status = :status';
            $params['status'] = $status;
        }
        if ($query !== '') {
            $where[] = '(rr.return_number LIKE :q1 OR o.order_number LIKE :q2 OR o.customer_name LIKE :q3 OR o.email LIKE :q4)';
            $like = '%' . $query . '%';
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
        }
        $clause = implode(' AND ', $where);
        $total = Database::int("SELECT COUNT(*) FROM return_requests rr JOIN orders o ON o.id = rr.order_id WHERE {$clause}", $params);
        $pager = paginate($total, $page, $per);
        $rows = Database::all(
            "SELECT rr.*, o.order_number, o.customer_name, o.email,
                    (SELECT COUNT(*) FROM return_request_items ri WHERE ri.return_request_id = rr.id) AS line_count
               FROM return_requests rr JOIN orders o ON o.id = rr.order_id
              WHERE {$clause} ORDER BY rr.created_at DESC, rr.id DESC
              LIMIT {$pager['limit']} OFFSET {$pager['offset']}", $params
        );
        return ['rows' => $rows, 'total' => $total, 'pager' => $pager];
    }

    public static function openCount(): int
    {
        return Database::int("SELECT COUNT(*) FROM return_requests WHERE status IN ('REQUESTED','APPROVED','IN_TRANSIT','RECEIVED','INSPECTING')");
    }

    private static function restockItems(array $request, bool $restock): void
    {
        if (!$restock) {
            return;
        }
        $items = Database::all(
            'SELECT oi.product_id, ri.quantity, ri.item_title FROM return_request_items ri
               JOIN order_items oi ON oi.id = ri.order_item_id WHERE ri.return_request_id = :id',
            ['id' => (int) $request['id']]
        );
        foreach ($items as $item) {
            if (empty($item['product_id'])) {
                continue;
            }
            $productId = (int) $item['product_id'];
            $qty = (int) $item['quantity'];
            Database::run('UPDATE products SET stock = stock + :qty, sold_count = GREATEST(0, sold_count - :sold), updated_at = :now WHERE id = :id', [
                'qty' => $qty, 'sold' => $qty, 'now' => date('Y-m-d H:i:s'), 'id' => $productId,
            ]);
            Inventory::record($productId, $qty, 'return', 'Restock from return ' . $request['return_number'] . ': ' . $item['item_title'], (string) $request['return_number']);
        }
    }

    private static function addEvent(int $id, string $status, string $message, string $actorType, ?int $actorId): void
    {
        Database::insert('return_events', [
            'return_request_id' => $id, 'status' => $status,
            'message' => substr($message, 0, 500), 'actor_type' => $actorType,
            'actor_id' => $actorId, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private static function notifyStatus(array $request, string $status): void
    {
        $labels = [
            'APPROVED' => 'Return approved', 'REJECTED' => 'Return request declined',
            'IN_TRANSIT' => 'Return parcel sent', 'RECEIVED' => 'Return parcel received',
            'INSPECTING' => 'Return is being inspected', 'REFUNDED' => 'Refund recorded',
            'EXCHANGED' => 'Exchange recorded', 'CLOSED' => 'Return case closed',
            'CANCELLED' => 'Return request cancelled',
        ];
        $userId = (int) ($request['user_id'] ?? 0);
        if ($userId > 0) {
            Notifier::notify($userId, ($labels[$status] ?? 'Return updated') . ' · ' . $request['return_number'], 'Your return request status is now ' . strtolower(str_replace('_', ' ', $status)) . '.', $status === 'REJECTED' ? 'warning' : 'order', '/account/return.php?id=' . (int) $request['id']);
        }
    }

    private static function defaultMessage(string $status): string
    {
        return match ($status) {
            'APPROVED' => 'Return approved. Follow the return-shipping instructions from our team.',
            'REJECTED' => 'Return request declined. Contact support if you need clarification.',
            'IN_TRANSIT' => 'Return parcel is on its way to the store.',
            'RECEIVED' => 'Return parcel received by the store.',
            'INSPECTING' => 'Returned item is being inspected.',
            'CLOSED' => 'Return case closed by the store.',
            'CANCELLED' => 'Return request cancelled.',
            default => 'Return request updated.',
        };
    }
}

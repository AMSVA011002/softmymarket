<?php
declare(strict_types=1);

/** Product-linked customer/support conversations with private image attachments. */
final class SellerMessages
{
    private const MAX_ATTACHMENTS = 3;
    private const MAX_FILE_BYTES = 2097152;
    private const MAX_BODY_CHARS = 4000;
    private const ALLOWED_IMAGES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /** @return array{rows:list<array<string,mixed>>,pager:array<string,mixed>,total:int} */
    public static function forCustomer(int $userId, int $page = 1): array
    {
        $total = Database::int('SELECT COUNT(*) FROM seller_conversations WHERE user_id = :uid', ['uid' => $userId]);
        $pager = paginate($total, $page, 20);
        $rows = Database::all(
            "SELECT c.*, (SELECT m.body FROM seller_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS latest_body,
                    (SELECT m.sender_role FROM seller_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS latest_sender,
                    (SELECT COUNT(*) FROM seller_messages m WHERE m.conversation_id = c.id AND m.sender_role <> 'customer' AND m.customer_read_at IS NULL) AS unread_count
               FROM seller_conversations c
              WHERE c.user_id = :uid
           ORDER BY c.last_message_at DESC, c.id DESC
              LIMIT {$pager['limit']} OFFSET {$pager['offset']}",
            ['uid' => $userId]
        );
        return ['rows' => $rows, 'pager' => $pager, 'total' => $total];
    }

    /** @return array{rows:list<array<string,mixed>>,pager:array<string,mixed>,total:int} */
    public static function forTeam(int $page = 1, string $status = '', string $search = ''): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (in_array($status, ['open', 'closed'], true)) {
            $where[] = 'c.status = :status';
            $params['status'] = $status;
        }
        if ($search !== '') {
            $where[] = '(u.name LIKE :q1 OR u.email LIKE :q2 OR c.product_title LIKE :q3 OR c.product_sku LIKE :q4)';
            $like = '%' . $search . '%';
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
        }
        $whereSql = implode(' AND ', $where);
        $total = Database::int(
            "SELECT COUNT(*) FROM seller_conversations c JOIN users u ON u.id = c.user_id WHERE {$whereSql}",
            $params
        );
        $pager = paginate($total, $page, 25);
        $rows = Database::all(
            "SELECT c.*, u.name AS customer_name, u.email AS customer_email,
                    (SELECT m.body FROM seller_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS latest_body,
                    (SELECT m.sender_role FROM seller_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS latest_sender,
                    (SELECT COUNT(*) FROM seller_messages m WHERE m.conversation_id = c.id AND m.sender_role = 'customer' AND m.team_read_at IS NULL) AS unread_count
               FROM seller_conversations c JOIN users u ON u.id = c.user_id
              WHERE {$whereSql}
           ORDER BY c.last_message_at DESC, c.id DESC
              LIMIT {$pager['limit']} OFFSET {$pager['offset']}",
            $params
        );
        return ['rows' => $rows, 'pager' => $pager, 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public static function customerConversation(int $id, int $userId): ?array
    {
        return Database::one(
            'SELECT c.*, u.name AS customer_name, u.email AS customer_email
               FROM seller_conversations c JOIN users u ON u.id = c.user_id
              WHERE c.id = :id AND c.user_id = :uid LIMIT 1',
            ['id' => $id, 'uid' => $userId]
        );
    }

    /** @return array<string,mixed>|null */
    public static function teamConversation(int $id): ?array
    {
        return Database::one(
            'SELECT c.*, u.name AS customer_name, u.email AS customer_email
               FROM seller_conversations c JOIN users u ON u.id = c.user_id
              WHERE c.id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function messages(int $conversationId): array
    {
        return Database::all(
            'SELECT * FROM seller_messages WHERE conversation_id = :cid ORDER BY id ASC',
            ['cid' => $conversationId]
        );
    }

    /** @return array<int,list<array<string,mixed>>> Attachments keyed by message id. */
    public static function attachments(int $conversationId): array
    {
        $rows = Database::all(
            'SELECT a.* FROM seller_message_attachments a
              JOIN seller_messages m ON m.id = a.message_id
             WHERE m.conversation_id = :cid ORDER BY a.id ASC',
            ['cid' => $conversationId]
        );
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['message_id']][] = $row;
        }
        return $grouped;
    }

    /** Mark messages visible to the current conversation participant. */
    public static function markRead(int $conversationId, bool $forTeam): void
    {
        $now = date('Y-m-d H:i:s');
        if ($forTeam) {
            Database::run(
                "UPDATE seller_messages SET team_read_at = :now WHERE conversation_id = :cid AND sender_role = 'customer' AND team_read_at IS NULL",
                ['now' => $now, 'cid' => $conversationId]
            );
            Database::update('seller_conversations', ['last_team_read_at' => $now], 'id = :id', ['id' => $conversationId]);
        } else {
            Database::run(
                "UPDATE seller_messages SET customer_read_at = :now WHERE conversation_id = :cid AND sender_role IN ('admin','staff') AND customer_read_at IS NULL",
                ['now' => $now, 'cid' => $conversationId]
            );
            Database::update('seller_conversations', ['last_customer_read_at' => $now], 'id = :id', ['id' => $conversationId]);
        }
    }

    /** Start or continue an open thread for a product as the signed-in customer. */
    public static function sendAsCustomer(int $userId, int $productId, int $conversationId, string $body, array $files): int
    {
        $conversation = null;
        $product = null;
        if ($conversationId > 0) {
            $conversation = self::customerConversation($conversationId, $userId);
            if ($conversation === null) {
                throw new InvalidArgumentException('Conversation not found.');
            }
        } else {
            $product = Catalog::product($productId);
            if ($product === null || $product['status'] !== 'active') {
                throw new InvalidArgumentException('That product is no longer available.');
            }
            $conversation = Database::one(
                "SELECT * FROM seller_conversations WHERE user_id = :uid AND product_id = :pid AND status = 'open' ORDER BY id DESC LIMIT 1",
                ['uid' => $userId, 'pid' => $productId]
            );
        }
        $user = Users::find($userId);
        if ($user === null || $user['role'] !== 'customer' || $user['status'] !== 'active') {
            throw new InvalidArgumentException('An active customer account is required to message the seller.');
        }
        $storedFiles = self::storeAttachments($files);
        try {
            $body = self::validateBody($body, $storedFiles);
            $conversationId = Database::tx(static function () use ($conversation, $product, $user, $userId, $productId, $body, $storedFiles): int {
                $now = date('Y-m-d H:i:s');
                if ($conversation === null) {
                    if ($product === null) {
                        throw new InvalidArgumentException('Product details are missing.');
                    }
                    $conversationId = Database::insert('seller_conversations', [
                        'user_id' => $userId,
                        'product_id' => $productId,
                        'product_title' => (string) $product['title'],
                        'product_sku' => (string) $product['sku'],
                        'status' => 'open',
                        'last_message_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    $conversationId = (int) $conversation['id'];
                }
                self::insertMessage($conversationId, $userId, (string) $user['name'], 'customer', $body, $storedFiles, $now);
                Database::update('seller_conversations', [
                    'status' => 'open', 'last_message_at' => $now, 'updated_at' => $now,
                ], 'id = :id AND user_id = :uid', ['id' => $conversationId, 'uid' => $userId]);
                return $conversationId;
            });
        } catch (Throwable $e) {
            self::removeStoredFiles($storedFiles);
            throw $e;
        }
        try {
            Notifier::notifyTeam('New product question', $user['name'] . ' sent a message about ' . ($product['title'] ?? $conversation['product_title']), 'info', '/admin/messages.php?tab=product');
            Audit::log('seller_message.customer_sent', 'seller_conversation', $conversationId, ['product_id' => $productId]);
        } catch (Throwable $e) {
            error_log('Seller message notification failed: ' . $e->getMessage());
        }
        return $conversationId;
    }

    /** Reply as an authenticated admin/staff member. */
    public static function sendAsTeam(int $conversationId, int $senderId, string $body, array $files): void
    {
        $conversation = self::teamConversation($conversationId);
        if ($conversation === null) {
            throw new InvalidArgumentException('Conversation not found.');
        }
        $user = Users::find($senderId);
        if ($user === null || !in_array($user['role'], ['admin', 'staff'], true) || $user['status'] !== 'active') {
            throw new InvalidArgumentException('An active store team account is required to reply.');
        }
        $role = (string) $user['role'];
        $storedFiles = self::storeAttachments($files);
        $now = date('Y-m-d H:i:s');
        try {
            $body = self::validateBody($body, $storedFiles);
            Database::tx(static function () use ($conversationId, $senderId, $user, $role, $body, $storedFiles, $now): void {
                self::insertMessage($conversationId, $senderId, (string) $user['name'], $role, $body, $storedFiles, $now);
                Database::update('seller_conversations', [
                    'status' => 'open', 'last_message_at' => $now, 'updated_at' => $now,
                ], 'id = :id', ['id' => $conversationId]);
            });
        } catch (Throwable $e) {
            self::removeStoredFiles($storedFiles);
            throw $e;
        }
        try {
            Notifier::notify((int) $conversation['user_id'], 'Reply from the store', 'The store replied about ' . $conversation['product_title'] . '.', 'info', '/account/message.php?id=' . $conversationId);
            Audit::log('seller_message.team_replied', 'seller_conversation', $conversationId);
        } catch (Throwable $e) {
            error_log('Seller reply notification failed: ' . $e->getMessage());
        }
    }

    /** Look up one attachment; the caller must verify participant access. */
    public static function attachment(int $id): ?array
    {
        return Database::one(
            'SELECT a.*, m.conversation_id, c.user_id
               FROM seller_message_attachments a
               JOIN seller_messages m ON m.id = a.message_id
               JOIN seller_conversations c ON c.id = m.conversation_id
              WHERE a.id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /** @param list<array<string,mixed>> $files */
    private static function insertMessage(int $conversationId, int $senderId, string $senderName, string $role, string $body, array $files, string $now): int
    {
        $messageId = Database::insert('seller_messages', [
            'conversation_id' => $conversationId,
            'sender_id' => $senderId,
            'sender_name' => mb_substr($senderName, 0, 120),
            'sender_role' => $role,
            'body' => $body,
            'customer_read_at' => $role === 'customer' ? $now : null,
            'team_read_at' => $role === 'customer' ? null : $now,
            'created_at' => $now,
        ]);
        foreach ($files as $file) {
            Database::insert('seller_message_attachments', [
                'message_id' => $messageId,
                'storage_name' => $file['storage_name'],
                'original_name' => $file['original_name'],
                'mime_type' => $file['mime_type'],
                'file_size' => $file['file_size'],
                'created_at' => $now,
            ]);
        }
        return $messageId;
    }

    /** @return list<array<string,mixed>> */
    private static function storeAttachments(array $upload): array
    {
        if (!isset($upload['name'])) {
            return [];
        }
        $names = is_array($upload['name']) ? $upload['name'] : [$upload['name']];
        $errors = is_array($upload['error'] ?? null) ? $upload['error'] : [(int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE)];
        $tmpNames = is_array($upload['tmp_name'] ?? null) ? $upload['tmp_name'] : [(string) ($upload['tmp_name'] ?? '')];
        $sizes = is_array($upload['size'] ?? null) ? $upload['size'] : [(int) ($upload['size'] ?? 0)];
        $stored = [];
        $selected = 0;
        foreach ($names as $index => $name) {
            $error = (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $selected++;
            if ($selected > self::MAX_ATTACHMENTS) {
                self::removeStoredFiles($stored);
                throw new InvalidArgumentException('Attach no more than three images to a message.');
            }
            $tmpPath = (string) ($tmpNames[$index] ?? '');
            if ($error === UPLOAD_ERR_OK) {
                $imageInfo = @getimagesize($tmpPath);
                if (!is_array($imageInfo) || (int) $imageInfo[0] < 1 || (int) $imageInfo[1] < 1 || (int) $imageInfo[0] * (int) $imageInfo[1] > 12000000) {
                    self::removeStoredFiles($stored);
                    throw new InvalidArgumentException('Images must be valid and no larger than 12 megapixels.');
                }
            }
            $result = Uploads::store([
                'name' => is_string($name) ? $name : '',
                'type' => '',
                'tmp_name' => (string) ($tmpNames[$index] ?? ''),
                'error' => $error,
                'size' => (int) ($sizes[$index] ?? 0),
            ], 'seller-messages', self::ALLOWED_IMAGES, self::MAX_FILE_BYTES, true);
            if (empty($result['ok'])) {
                self::removeStoredFiles($stored);
                throw new InvalidArgumentException((string) ($result['error'] ?? 'An image could not be uploaded.'));
            }
            $original = basename(str_replace('\\', '/', (string) $name));
            $original = preg_replace('/[\x00-\x1F\x7F]/u', '', $original) ?? 'image';
            $stored[] = [
                'storage_name' => (string) $result['file'],
                'original_name' => mb_substr($original !== '' ? $original : 'image', 0, 180),
                'mime_type' => (string) $result['mime'],
                'file_size' => (int) $result['size'],
                'path' => (string) $result['path'],
            ];
        }
        return $stored;
    }

    /** @param list<array<string,mixed>> $files */
    private static function validateBody(string $body, array $files): string
    {
        $body = trim($body);
        if ($body === '' && $files === []) {
            throw new InvalidArgumentException('Write a message or attach an image.');
        }
        if (mb_strlen($body) > self::MAX_BODY_CHARS) {
            throw new InvalidArgumentException('Messages must be 4,000 characters or fewer.');
        }
        return $body;
    }

    /** @param list<array<string,mixed>> $files */
    private static function removeStoredFiles(array $files): void
    {
        foreach ($files as $file) {
            Uploads::delete((string) ($file['path'] ?? ''));
        }
    }
}

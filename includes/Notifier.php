<?php
declare(strict_types=1);

/**
 * In-app notifications (bell menu for customers, alerts for the store team).
 */
final class Notifier
{
    public static function notify(int $userId, string $title, string $body, string $type = 'info', ?string $link = null): int
    {
        return Database::insert('notifications', [
            'user_id'    => $userId,
            'title'      => substr($title, 0, 160),
            'body'       => substr($body, 0, 500),
            'type'       => in_array($type, ['info', 'success', 'warning', 'danger', 'order', 'payment'], true) ? $type : 'info',
            'link'       => $link !== null ? substr($link, 0, 255) : null,
            'read_at'    => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Notify every active administrator and store user. */
    public static function notifyTeam(string $title, string $body, string $type = 'order', ?string $link = null): void
    {
        $team = Database::all("SELECT id FROM users WHERE role IN ('admin', 'staff') AND status = 'active'");
        foreach ($team as $member) {
            self::notify((int) $member['id'], $title, $body, $type, $link);
        }
    }

    public static function unreadCount(?int $userId): int
    {
        if ($userId === null) {
            return 0;
        }
        return Database::int('SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND read_at IS NULL', ['uid' => $userId]);
    }

    /** @return list<array<string,mixed>> */
    public static function recent(?int $userId, int $limit = 8): array
    {
        if ($userId === null) {
            return [];
        }
        return Database::all(
            'SELECT * FROM notifications WHERE user_id = :uid ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)),
            ['uid' => $userId]
        );
    }

    public static function paginate(int $userId, int $page = 1, int $per = 20): array
    {
        $total = Database::int('SELECT COUNT(*) FROM notifications WHERE user_id = :uid', ['uid' => $userId]);
        $pager = paginate($total, $page, $per);
        $rows = Database::all(
            'SELECT * FROM notifications WHERE user_id = :uid ORDER BY id DESC LIMIT ' . $pager['limit'] . ' OFFSET ' . $pager['offset'],
            ['uid' => $userId]
        );
        return ['rows' => $rows, 'total' => $total, 'pager' => $pager];
    }

    public static function markRead(int $id, int $userId): void
    {
        Database::run(
            'UPDATE notifications SET read_at = :now WHERE id = :id AND user_id = :uid AND read_at IS NULL',
            ['now' => date('Y-m-d H:i:s'), 'id' => $id, 'uid' => $userId]
        );
    }

    public static function markAllRead(int $userId): void
    {
        Database::run(
            'UPDATE notifications SET read_at = :now WHERE user_id = :uid AND read_at IS NULL',
            ['now' => date('Y-m-d H:i:s'), 'uid' => $userId]
        );
    }

    public static function delete(int $id, int $userId): void
    {
        Database::run('DELETE FROM notifications WHERE id = :id AND user_id = :uid', ['id' => $id, 'uid' => $userId]);
    }
}

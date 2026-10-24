<?php
declare(strict_types=1);

/**
 * Append-only audit trail for security-relevant actions.
 *
 * Writing an audit entry must never break a user flow, so failures are logged
 * to the PHP error log and swallowed.
 */
final class Audit
{
    public static function log(string $action, string $entity = 'system', int|string|null $entityId = null, array $meta = []): void
    {
        try {
            $actor = Auth::id();
            Database::insert('audit_log', [
                'user_id'     => $actor,
                'action'      => substr($action, 0, 80),
                'entity'      => substr($entity, 0, 60),
                'entity_id'   => $entityId !== null ? (string) $entityId : null,
                'meta'        => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_SLASHES),
                'ip_address'  => Security::clientIp(),
                'user_agent'  => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            error_log('Audit log failed: ' . $e->getMessage());
        }
    }

    /** @return list<array<string,mixed>> */
    public static function recent(int $limit = 50, ?int $userId = null): array
    {
        $sql = 'SELECT a.*, u.name AS user_name, u.email AS user_email
                  FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id';
        $params = [];
        if ($userId !== null) {
            $sql .= ' WHERE a.user_id = :uid';
            $params['uid'] = $userId;
        }
        $sql .= ' ORDER BY a.id DESC LIMIT ' . max(1, min(500, $limit));
        return Database::all($sql, $params);
    }
}

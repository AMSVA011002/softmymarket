<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Auth::check()) {
    json_response(['error' => 'Sign in to view notifications.', 'login' => true], 401);
}

$userId = (int) Auth::id();

if (is_post()) {
    Csrf::verifyRequest();
    $action = (string) request_input('action', '');
    if ($action === 'read_all') {
        Notifier::markAllRead($userId);
        json_response(['ok' => true, 'unread' => 0]);
    }
    if ($action === 'read') {
        Notifier::markRead((int) request_input('id', '0'), $userId);
        json_response(['ok' => true, 'unread' => Notifier::unreadCount($userId)]);
    }
    json_response(['error' => 'Unknown action.'], 422);
}

json_response([
    'unread' => Notifier::unreadCount($userId),
    'items' => array_map(static fn (array $row): array => [
        'title' => $row['title'],
        'body' => $row['body'],
        'link' => $row['link'],
        'read' => $row['read_at'] !== null,
        'created' => time_ago($row['created_at']),
    ], Notifier::recent($userId, 10)),
]);

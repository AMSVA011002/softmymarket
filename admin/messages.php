<?php
/**
 * Contact messages and newsletter subscribers.
 */
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
admin_require('messages.view');

$tab = (string) query('tab', 'messages');
$status = (string) query('status', '');
$where = ['1 = 1'];
$params = [];
if (in_array($status, ['new', 'open', 'closed', 'spam'], true)) {
    $where[] = 'status = :status';
    $params['status'] = $status;
}
$whereSql = implode(' AND ', $where);
$total = Database::int("SELECT COUNT(*) FROM contact_messages WHERE {$whereSql}", $params);
$pager = paginate($total, query_int('page', 1), 20);
$messages = Database::all(
    "SELECT * FROM contact_messages WHERE {$whereSql} ORDER BY created_at DESC LIMIT {$pager['limit']} OFFSET {$pager['offset']}",
    $params
);
$subscribers = Database::all('SELECT * FROM newsletter_subscribers ORDER BY created_at DESC LIMIT 200');
$subscriberCount = Database::int('SELECT COUNT(*) FROM newsletter_subscribers');
$returnUrl = admin_url('messages.php', ['status' => $status, 'tab' => $tab]);

$adminTitle = 'Messages';
$adminSubtitle = number_format($total) . ' messages · ' . number_format($subscriberCount) . ' newsletter subscribers';
require __DIR__ . '/includes/layout-top.php';
?>
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab === 'messages' ? 'active' : '' ?>" href="<?= e(admin_url('messages.php', ['tab' => 'messages'])) ?>">Contact messages</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'newsletter' ? 'active' : '' ?>" href="<?= e(admin_url('messages.php', ['tab' => 'newsletter'])) ?>">Newsletter</a></li>
</ul>

<?php if ($tab === 'newsletter'): ?>
  <div class="card-soft p-0">
    <div class="table-responsive">
      <table class="table table-admin mb-0">
        <thead><tr><th>Email</th><th>Source</th><th>Subscribed</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($subscribers as $subscriber): ?>
            <tr>
              <td><?= e((string) $subscriber['email']) ?></td>
              <td class="small text-ink-500"><?= e(humanize((string) $subscriber['source'])) ?></td>
              <td class="small text-ink-500"><?= e(fmt_date((string) $subscriber['created_at'])) ?></td>
              <td class="text-end">
                <?php if (Auth::can('settings.manage')): ?>
                  <form method="post" action="/admin/actions.php" data-confirm="Remove this subscriber?">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="subscriber_delete">
                    <input type="hidden" name="subscriber_id" value="<?= (int) $subscriber['id'] ?>">
                    <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                    <button class="btn btn-sm btn-soft" type="submit"><i class="bi bi-trash"></i></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($subscribers === []): ?><tr><td colspan="4" class="text-center small text-ink-500 py-4">No subscribers yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php else: ?>
  <div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach (['' => 'All', 'new' => 'New', 'open' => 'Open', 'closed' => 'Closed', 'spam' => 'Spam'] as $value => $label): ?>
      <a class="btn btn-sm <?= $status === $value ? 'btn-brand' : 'btn-soft' ?>" href="<?= e(admin_url('messages.php', ['status' => $value])) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php foreach ($messages as $message): ?>
    <div class="card-soft p-3 mb-2">
      <div class="d-flex flex-wrap justify-content-between gap-2">
        <div>
          <span class="fw-semibold small"><?= e((string) $message['name']) ?></span>
          <span class="badge text-bg-<?= $message['status'] === 'new' ? 'warning' : 'light' ?> ms-1"><?= e(humanize((string) $message['status'])) ?></span>
          <span class="badge text-bg-light"><?= e(humanize((string) $message['topic'])) ?></span>
        </div>
        <span class="small text-ink-400"><?= e(fmt_datetime((string) $message['created_at'])) ?></span>
      </div>
      <div class="small text-ink-500 mb-1">
        <a class="text-decoration-none" href="mailto:<?= e((string) $message['email']) ?>"><?= e((string) $message['email']) ?></a>
        <?php if (!empty($message['phone'])): ?> · <?= e(phone_display((string) $message['phone'])) ?><?php endif; ?>
      </div>
      <?php if (!empty($message['subject'])): ?><p class="small fw-semibold mb-1"><?= e((string) $message['subject']) ?></p><?php endif; ?>
      <p class="small text-ink-700 mb-2"><?= nl2br(e((string) $message['message'])) ?></p>
      <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-sm btn-brand" href="mailto:<?= e((string) $message['email']) ?>?subject=<?= rawurlencode('Re: ' . (string) ($message['subject'] ?: 'Your message to ' . Settings::get('store_name'))) ?>"><i class="bi bi-reply me-1"></i> Reply by email</a>
        <?php if ($message['status'] !== 'open'): ?>
          <form method="post" action="/admin/actions.php">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="message_read">
            <input type="hidden" name="message_id" value="<?= (int) $message['id'] ?>">
            <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
            <button class="btn btn-sm btn-soft" type="submit">Mark handled</button>
          </form>
        <?php endif; ?>
        <?php if (Auth::can('settings.manage')): ?>
          <form method="post" action="/admin/actions.php" data-confirm="Delete this message?">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="message_delete">
            <input type="hidden" name="message_id" value="<?= (int) $message['id'] ?>">
            <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
            <button class="btn btn-sm btn-soft" type="submit"><i class="bi bi-trash"></i></button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if ($messages === []): ?>
    <div class="card-soft p-4 text-center small text-ink-500"><i class="bi bi-chat-dots fs-4 d-block mb-2"></i>No messages in this queue.</div>
  <?php endif; ?>
  <div class="mt-3"><?= pagination_links($pager, ['status' => $status]) ?></div>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>

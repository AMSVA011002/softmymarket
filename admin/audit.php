<?php
/**
 * Audit log — who changed what, and when.
 */
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
admin_require('settings.manage');

$page = query_int('page', 1);
$total = Database::int('SELECT COUNT(*) FROM audit_log');
$pager = paginate($total, $page, 50);
$rows = Database::all(
    'SELECT a.*, u.name AS user_name, u.email AS user_email
       FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
   ORDER BY a.id DESC LIMIT ' . $pager['limit'] . ' OFFSET ' . $pager['offset']
);

$adminTitle = 'Audit log';
$adminSubtitle = number_format($total) . ' security-relevant events';
require __DIR__ . '/includes/layout-top.php';
?>
<div class="card-soft p-0">
  <div class="table-responsive">
    <table class="table table-admin mb-0">
      <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th class="d-none d-lg-table-cell">Details</th><th class="d-none d-xl-table-cell">IP</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td class="small text-nowrap"><?= e(fmt_datetime((string) $row['created_at'])) ?></td>
            <td class="small">
              <?php if (!empty($row['user_name'])): ?>
                <span class="d-block"><?= e((string) $row['user_name']) ?></span>
                <span class="text-ink-400" style="font-size:.68rem"><?= e((string) $row['user_email']) ?></span>
              <?php else: ?>
                <span class="text-ink-400">Guest / system</span>
              <?php endif; ?>
            </td>
            <td class="small"><code style="font-size:.72rem"><?= e((string) $row['action']) ?></code></td>
            <td class="small"><?= e((string) $row['entity']) ?><?= $row['entity_id'] !== null ? ' #' . e((string) $row['entity_id']) : '' ?></td>
            <td class="d-none d-lg-table-cell small text-ink-500 text-break" style="max-width:320px"><?= e(limit_text((string) ($row['meta'] ?? ''), 120)) ?></td>
            <td class="d-none d-xl-table-cell small text-ink-400"><?= e((string) ($row['ip_address'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($rows === []): ?><tr><td colspan="6" class="text-center small text-ink-500 py-4">Nothing logged yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="mt-3"><?= pagination_links($pager) ?></div>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>

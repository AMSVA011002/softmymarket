<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
Auth::requireLogin();

$page = query_int('page', 1, 1, 1000);
$result = Notifier::paginate((int) Auth::id(), $page, 20);
$accountActive = 'notifications';

$pageTitle = 'Notifications';
$noindex = true;
require INCLUDES_PATH . '/layout/header.php';
?>
<section class="section">
  <div class="container-page">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h1 class="h4 fw-bold mb-0">Notifications</h1>
      <button class="btn btn-soft btn-sm" type="button" data-mark-all-read><i class="bi bi-check2-all me-1"></i> Mark all read</button>
    </div>
    <div class="row g-4">
      <div class="col-lg-3"><?php require INCLUDES_PATH . '/account-nav.php'; ?></div>
      <div class="col-lg-9">
        <div class="card-soft p-3 p-md-4">
          <?php if ($result['rows'] === []): ?>
            <p class="small text-ink-500 mb-0">Nothing here yet. Order updates and payment confirmations will appear here.</p>
          <?php else: ?>
            <?php foreach ($result['rows'] as $note): ?>
              <div class="d-flex gap-3 py-3 border-bottom <?= $note['read_at'] === null ? 'unread' : '' ?>" data-notification-item>
                <i class="bi <?= $note['type'] === 'order' ? 'bi-box-seam' : ($note['type'] === 'payment' ? 'bi-credit-card' : 'bi-bell') ?> fs-5 text-brand"></i>
                <div class="flex-grow-1">
                  <div class="small fw-semibold"><?= e($note['title']) ?></div>
                  <div class="text-ink-600" style="font-size:.8rem"><?= e($note['body']) ?></div>
                  <div class="text-ink-400" style="font-size:.68rem"><?= e(time_ago((string) $note['created_at'])) ?></div>
                </div>
                <?php if (!empty($note['link'])): ?>
                  <a class="btn btn-soft btn-sm align-self-center" href="<?= e($note['link']) ?>">Open</a>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
            <div class="mt-3"><?= pagination_links($result['pager'], []) ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

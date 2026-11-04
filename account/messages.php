<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
Auth::requireLogin();
if (!Auth::isCustomer()) {
    abort(403, 'Customer messages are only available to customer accounts.');
}

$conversationPage = SellerMessages::forCustomer((int) Auth::id(), query_int('page', 1));
$conversations = $conversationPage['rows'];
$accountActive = 'messages';
$pageTitle = 'Messages with the seller';
$noindex = true;
require INCLUDES_PATH . '/layout/header.php';
?>
<section class="section">
  <div class="container-page">
    <div class="row g-4">
      <div class="col-lg-3"><?php require INCLUDES_PATH . '/account-nav.php'; ?></div>
      <div class="col-lg-9">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
          <div><h1 class="h4 fw-bold mb-1">Messages with the seller</h1><p class="small text-ink-500 mb-0">Questions about products and replies from our store team.</p></div>
          <a class="btn btn-soft btn-sm" href="/shop.php"><i class="bi bi-bag me-1"></i> Browse products</a>
        </div>
        <div class="card-soft p-3 p-md-4">
          <?php if ($conversations === []): ?>
            <?= View::emptyState('bi-chat-dots', 'No product messages yet', 'Use “Ask From Seller” on a product page to start a private conversation with our team.') ?>
          <?php else: ?>
            <?php foreach ($conversations as $conversation): ?>
              <a class="seller-thread-link d-flex align-items-start gap-3 py-3 border-bottom text-decoration-none" href="/account/message.php?id=<?= (int) $conversation['id'] ?>">
                <span class="seller-thread-icon"><i class="bi bi-box-seam"></i></span>
                <span class="flex-grow-1 min-w-0">
                  <span class="d-flex flex-wrap justify-content-between gap-2">
                    <strong class="text-ink-900"><?= e($conversation['product_title']) ?></strong>
                    <time class="small text-ink-400"><?= e(time_ago((string) $conversation['last_message_at'])) ?></time>
                  </span>
                  <span class="small text-ink-500 d-block text-truncate"><?= e((string) ($conversation['latest_body'] ?? 'Image attachment')) ?></span>
                  <span class="small text-ink-400"><?= e(humanize((string) $conversation['status'])) ?> conversation</span>
                </span>
                <?php if ((int) $conversation['unread_count'] > 0): ?><span class="badge text-bg-danger"><?= (int) $conversation['unread_count'] ?> new</span><?php endif; ?>
              </a>
            <?php endforeach; ?>
            <div class="mt-3"><?= pagination_links($conversationPage['pager']) ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
Auth::requireLogin();
if (!Auth::isCustomer()) {
    abort(403, 'Customer messages are only available to customer accounts.');
}

$id = query_int('id');
$conversation = SellerMessages::customerConversation($id, (int) Auth::id());
if ($conversation === null) {
    abort(404, 'Conversation not found.');
}
SellerMessages::markRead($id, false);
$messages = SellerMessages::messages($id);
$attachments = SellerMessages::attachments($id);
$accountActive = 'messages';
$pageTitle = 'Conversation about ' . $conversation['product_title'];
$noindex = true;
$productUrl = null;
if (!empty($conversation['product_id'])) {
    $liveProduct = Catalog::product((int) $conversation['product_id']);
    if ($liveProduct !== null && $liveProduct['status'] === 'active') {
        $productUrl = '/product.php?slug=' . rawurlencode((string) $liveProduct['slug']);
    }
}
require INCLUDES_PATH . '/layout/header.php';
?>
<section class="section">
  <div class="container-page">
    <div class="row g-4">
      <div class="col-lg-3"><?php require INCLUDES_PATH . '/account-nav.php'; ?></div>
      <div class="col-lg-9">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
          <div>
            <a class="small text-decoration-none" href="/account/messages.php"><i class="bi bi-arrow-left me-1"></i> All messages</a>
            <h1 class="h4 fw-bold mb-1 mt-1">About <?= e($conversation['product_title']) ?></h1>
            <p class="small text-ink-500 mb-0">SKU <?= e($conversation['product_sku']) ?> · <?= e(humanize((string) $conversation['status'])) ?> conversation</p>
          </div>
          <?php if ($productUrl !== null): ?><a class="btn btn-soft btn-sm" href="<?= e($productUrl) ?>"><i class="bi bi-box-arrow-up-right me-1"></i> View product</a><?php endif; ?>
        </div>

        <div class="seller-chat-history card-soft p-3 p-md-4 mb-3" aria-label="Conversation history">
          <?php foreach ($messages as $message): $isCustomer = $message['sender_role'] === 'customer'; ?>
            <article class="seller-chat-message <?= $isCustomer ? 'from-customer' : 'from-store' ?>">
              <div class="seller-chat-meta"><strong><?= $isCustomer ? 'You' : e($message['sender_name']) ?></strong><time><?= e(fmt_datetime((string) $message['created_at'])) ?></time></div>
              <?php if (trim((string) $message['body']) !== ''): ?><div class="seller-chat-body"><?= nl2br(e((string) $message['body'])) ?></div><?php endif; ?>
              <?php foreach ($attachments[(int) $message['id']] ?? [] as $attachment): ?>
                <a class="seller-chat-attachment" href="/message-attachment.php?id=<?= (int) $attachment['id'] ?>" target="_blank" rel="noopener" aria-label="Open attached image <?= e($attachment['original_name']) ?>">
                  <img src="/message-attachment.php?id=<?= (int) $attachment['id'] ?>" alt="<?= e($attachment['original_name']) ?>" loading="lazy">
                </a>
              <?php endforeach; ?>
            </article>
          <?php endforeach; ?>
          <?php if ($messages === []): ?><p class="small text-ink-500 mb-0">Send a message to start the conversation.</p><?php endif; ?>
        </div>

        <div class="card-soft p-3 p-md-4">
          <h2 class="h6 fw-bold">Send a reply</h2>
          <form method="post" action="/actions/seller-message.php" enctype="multipart/form-data" data-disable-on-submit>
            <?= Csrf::field() ?>
            <input type="hidden" name="conversation_id" value="<?= $id ?>">
            <label class="form-label small" for="seller-message-body">Message</label>
            <textarea class="form-control mb-2" id="seller-message-body" name="body" rows="4" maxlength="4000" placeholder="Write your reply…"><?= e((string) old('seller_message_body', '')) ?></textarea>
            <label class="form-label small" for="seller-message-files">Attach images (up to 3)</label>
            <input class="form-control form-control-sm mb-2" id="seller-message-files" type="file" name="attachments[]" accept="image/jpeg,image/png,image/webp" multiple>
            <div class="form-text mb-3">JPEG, PNG or WebP · maximum 2 MB each. Attachments are only visible to you and the store team.</div>
            <button class="btn btn-brand" type="submit"><i class="bi bi-send me-1"></i> Send reply</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/layout/footer.php'; ?>

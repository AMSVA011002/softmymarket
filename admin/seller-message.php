<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
admin_require('messages.view');

$id = query_int('id');
$conversation = SellerMessages::teamConversation($id);
if ($conversation === null) {
    abort(404, 'Conversation not found.');
}
SellerMessages::markRead($id, true);
$messages = SellerMessages::messages($id);
$attachments = SellerMessages::attachments($id);
$returnUrl = admin_url('seller-message.php', ['id' => $id]);
$productUrl = null;
if (!empty($conversation['product_id'])) {
    $product = Catalog::product((int) $conversation['product_id']);
    if ($product !== null && $product['status'] === 'active') {
        $productUrl = '/product.php?slug=' . rawurlencode((string) $product['slug']);
    }
}
$adminTitle = 'Product enquiry';
$adminSubtitle = $conversation['customer_name'] . ' · ' . $conversation['customer_email'];
$adminActions = '<a class="btn btn-sm btn-soft" href="' . e(admin_url('messages.php', ['tab' => 'product'])) . '"><i class="bi bi-arrow-left me-1"></i> All product enquiries</a>';
require __DIR__ . '/includes/layout-top.php';
?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card-soft p-3 p-md-4 mb-3">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
          <h2 class="h6 fw-bold mb-1"><?= e($conversation['product_title']) ?></h2>
          <div class="small text-ink-500">SKU <?= e($conversation['product_sku']) ?> · <?= e(humanize($conversation['status'])) ?> · Started <?= e(fmt_datetime((string) $conversation['created_at'])) ?></div>
        </div>
        <?php if ($productUrl !== null): ?><a class="btn btn-soft btn-sm" href="<?= e($productUrl) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i> View product</a><?php endif; ?>
      </div>
      <div class="seller-chat-history" aria-label="Conversation history">
        <?php foreach ($messages as $message): $isCustomer = $message['sender_role'] === 'customer'; ?>
          <article class="seller-chat-message <?= $isCustomer ? 'from-customer' : 'from-store' ?>">
            <div class="seller-chat-meta"><strong><?= $isCustomer ? e($conversation['customer_name']) : e($message['sender_name']) ?></strong><time><?= e(fmt_datetime((string) $message['created_at'])) ?></time></div>
            <?php if (trim((string) $message['body']) !== ''): ?><div class="seller-chat-body"><?= nl2br(e((string) $message['body'])) ?></div><?php endif; ?>
            <?php foreach ($attachments[(int) $message['id']] ?? [] as $attachment): ?>
              <a class="seller-chat-attachment" href="/message-attachment.php?id=<?= (int) $attachment['id'] ?>" target="_blank" rel="noopener" aria-label="Open attached image <?= e($attachment['original_name']) ?>">
                <img src="/message-attachment.php?id=<?= (int) $attachment['id'] ?>" alt="<?= e($attachment['original_name']) ?>" loading="lazy">
              </a>
            <?php endforeach; ?>
          </article>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card-soft p-3 p-md-4">
      <h2 class="h6 fw-bold">Reply to customer</h2>
      <form method="post" action="/admin/actions.php" enctype="multipart/form-data" data-disable-on-submit>
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="seller_message_reply">
        <input type="hidden" name="conversation_id" value="<?= $id ?>">
        <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
        <label class="form-label small" for="reply-body">Message</label>
        <textarea class="form-control mb-2" id="reply-body" name="body" rows="4" maxlength="4000" placeholder="Write a reply…"><?= e((string) old('seller_team_message_body', '')) ?></textarea>
        <label class="form-label small" for="reply-files">Attach images (up to 3)</label>
        <input class="form-control form-control-sm mb-2" id="reply-files" type="file" name="attachments[]" accept="image/jpeg,image/png,image/webp" multiple>
        <div class="form-text mb-3">JPEG, PNG or WebP · maximum 2 MB each.</div>
        <button class="btn btn-brand" type="submit"><i class="bi bi-reply me-1"></i> Send reply</button>
      </form>
    </div>
  </div>
  <aside class="col-lg-4">
    <div class="card-soft p-3">
      <h2 class="h6 fw-bold">Customer</h2>
      <div class="small fw-semibold"><?= e($conversation['customer_name']) ?></div>
      <a class="small text-decoration-none" href="mailto:<?= e($conversation['customer_email']) ?>"><?= e($conversation['customer_email']) ?></a>
      <div class="small text-ink-500 mt-2">Replies here are saved to the customer's account history and trigger an in-app notification.</div>
    </div>
  </aside>
</div>
<?php require __DIR__ . '/includes/layout-bottom.php'; ?>

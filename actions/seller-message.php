<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!is_post()) {
    redirect('/');
}
Csrf::verifyRequest();
Auth::requireLogin();
if (!Auth::isCustomer()) {
    abort(403, 'Only customer accounts can send product questions.');
}
RateLimiter::guard('seller_message');

$userId = (int) Auth::id();
$productId = post_int('product_id');
$conversationId = post_int('conversation_id');
$body = trim((string) post('body', ''));
$product = $productId > 0 ? Catalog::product($productId) : null;
$return = $conversationId > 0
    ? '/account/message.php?id=' . $conversationId
    : ($product !== null ? '/product.php?slug=' . rawurlencode((string) $product['slug']) : '/shop.php');

try {
    $savedConversationId = SellerMessages::sendAsCustomer(
        $userId,
        $productId,
        $conversationId,
        $body,
        is_array($_FILES['attachments'] ?? null) ? $_FILES['attachments'] : []
    );
} catch (InvalidArgumentException $e) {
    remember_input(['seller_message_body' => $body]);
    flash('danger', $e->getMessage());
    redirect($return);
} catch (Throwable $e) {
    error_log('Product seller message failed: ' . $e->getMessage());
    remember_input(['seller_message_body' => $body]);
    flash('danger', 'Your message could not be sent. Your text was kept; please try again and reselect any images.');
    redirect($return);
}

flash('success', 'Your message was sent to the seller. You can continue the conversation from your account messages.');
redirect('/account/message.php?id=' . $savedConversationId);

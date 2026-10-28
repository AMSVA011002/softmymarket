<?php
/**
 * Dashboard write endpoint.
 *
 * One CSRF-protected dispatcher keeps every mutation in a single auditable
 * place: each branch checks its own permission, validates input and redirects
 * back to the page that posted the form.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (!is_post()) {
    redirect('/admin/index.php');
}
Csrf::verifyRequest();
RateLimiter::guard('api');

$action = (string) post('action', '');
$return = (string) post('return', '/admin/index.php');
if (!str_starts_with($return, '/admin/')) {
    $return = '/admin/index.php';
}

/** Redirect back with a message. */
$done = static function (string $type, string $message) use ($return): never {
    flash($type, $message);
    redirect($return);
};

/** Fetch an order or bail out. */
$requireOrder = static function (int $id) use ($done): array {
    $order = Orders::find($id);
    if ($order === null) {
        $done('danger', 'That order no longer exists.');
    }
    return $order;
};

switch ($action) {
    /* ------------------------------------------------------------- orders */
    case 'order_status':
        admin_require('orders.manage');
        $order = $requireOrder(post_int('order_id'));
        $status = strtoupper((string) post('status', ''));
        $message = trim((string) post('message', ''));
        $force = (bool) post('force');
        $result = OrderService::updateStatus((int) $order['id'], $status, $message, (string) Auth::role(), Auth::id(), $force);
        $done($result['ok'] ? 'success' : 'danger', (string) $result['message']);

    case 'order_tracking':
        admin_require('orders.manage');
        $order = $requireOrder(post_int('order_id'));
        OrderService::updateTracking(
            (int) $order['id'],
            trim((string) post('tracking_number', '')) ?: null,
            trim((string) post('carrier', '')) ?: null,
            trim((string) post('courier_url', '')) ?: null,
            trim((string) post('message', ''))
        );
        Audit::log('order.tracking_updated', 'order', (int) $order['id']);
        $done('success', 'Tracking details saved and the customer was notified.');

    case 'order_note':
        admin_require('orders.manage');
        $order = $requireOrder(post_int('order_id'));
        $note = trim((string) post('message', ''));
        if ($note === '') {
            $done('warning', 'Write a note first.');
        }
        Orders::addEvent((int) $order['id'], (string) $order['status'], $note, (bool) post('is_public', 1), (string) Auth::role(), Auth::id());
        $done('success', 'Note added to the order timeline.');

    case 'order_refund':
        admin_require('settings.manage');
        $order = $requireOrder(post_int('order_id'));
        $result = OrderService::updateStatus((int) $order['id'], 'REFUNDED', trim((string) post('message', 'Refund issued by ' . Auth::user()['name'])), 'admin', Auth::id());
        if ($result['ok']) {
            Database::update('orders', ['payment_status' => 'REFUNDED'], 'id = :id', ['id' => (int) $order['id']]);
        }
        $done($result['ok'] ? 'success' : 'danger', (string) $result['message']);

    /* ----------------------------------------------------------- payments */
    case 'payment_verify':
        admin_require('payments.verify');
        $payment = Payments::find(post_int('payment_id'));
        if ($payment === null) {
            $done('danger', 'Payment not found.');
        }
        Payments::verifyBankTransfer((int) $payment['id'], (int) Auth::id());
        Audit::log('payment.verified', 'payment', (int) $payment['id']);
        $done('success', 'Bank transfer verified — the order is marked paid.');

    case 'payment_mark_paid':
        admin_require('payments.verify');
        $payment = Payments::find(post_int('payment_id'));
        if ($payment === null) {
            $done('danger', 'Payment not found.');
        }
        Payments::markPaid((int) $payment['id'], trim((string) post('reference', '')) ?: null, ['manual' => true], Auth::id());
        Audit::log('payment.manually_paid', 'payment', (int) $payment['id']);
        $done('success', 'Payment marked as paid.');

    case 'payment_fail':
        admin_require('payments.verify');
        $payment = Payments::find(post_int('payment_id'));
        if ($payment === null) {
            $done('danger', 'Payment not found.');
        }
        Payments::markFailed((int) $payment['id'], ['manual' => true, 'reason' => (string) post('reason', '')]);
        $done('success', 'Payment marked as failed.');

    /* ------------------------------------------------------------ returns */
    case 'return_status':
        admin_require('orders.manage');
        $returnId = post_int('return_id');
        $result = ReturnService::transition($returnId, (string) post('status', ''), (string) post('message', ''), (string) Auth::role(), Auth::id());
        $done($result['ok'] ? 'success' : 'danger', $result['message']);

    case 'return_refund':
        admin_require('payments.verify');
        $result = ReturnService::refund(
            post_int('return_id'),
            (float) post('amount', '0'),
            (string) post('method', ''),
            (string) post('reference', ''),
            (bool) post('restock'),
            (string) post('message', ''),
            (int) Auth::id()
        );
        $done($result['ok'] ? 'success' : 'danger', $result['message']);

    case 'return_exchange':
        admin_require('orders.manage');
        $result = ReturnService::exchange(post_int('return_id'), (bool) post('restock'), (string) post('message', ''), (int) Auth::id());
        $done($result['ok'] ? 'success' : 'danger', $result['message']);

    /* ---------------------------------------------------------- catalogue */
    case 'product_save':
        admin_require('products.manage');
        $id = post_int('id');
        $imageUrls = [];
        if (array_key_exists('image_links', $_POST)) {
            // One URL per line; normalize, deduplicate and enforce a 20-image cap.
            foreach (preg_split('/\R+/', (string) post('image_links', '')) ?: [] as $rawImage) {
                $image = normalize_image_url(trim((string) $rawImage));
                if ($image !== null && !in_array($image, $imageUrls, true)) {
                    $imageUrls[] = $image;
                }
                if (count($imageUrls) >= 20) {
                    break;
                }
            }
        } else {
            // Backward-compatible fallback for older product forms or integrations.
            $legacyPrimary = normalize_image_url((string) post('image_url', ''));
            if ($legacyPrimary !== null) {
                $imageUrls[] = $legacyPrimary;
            }
            foreach ((array) ($_POST['gallery'] ?? []) as $rawImage) {
                $image = normalize_image_url(is_string($rawImage) ? $rawImage : null);
                if ($image !== null && !in_array($image, $imageUrls, true)) {
                    $imageUrls[] = $image;
                }
                if (count($imageUrls) >= 20) {
                    break;
                }
            }
        }
        $specNames = (array) ($_POST['spec_name'] ?? []);
        $specValues = (array) ($_POST['spec_value'] ?? []);
        $specs = [];
        foreach ($specNames as $index => $specName) {
            $specs[] = ['name' => $specName, 'value' => $specValues[$index] ?? ''];
        }
        $tags = array_values(array_filter(array_map('trim', explode(',', (string) post('tags', '')))));
        $data = [
            'title' => (string) post('title', ''),
            'slug' => (string) post('slug', ''),
            'sku' => (string) post('sku', ''),
            'category_id' => post_int('category_id'),
            'parent_category_id' => post_int('parent_category_id'),
            'brand_id' => post_int('brand_id'),
            'short_description' => (string) post('short_description', ''),
            'description' => (string) post('description', ''),
            'price' => (float) post('price', '0'),
            'compare_at_price' => (float) post('compare_at_price', '0'),
            'cost_price' => (float) post('cost_price', '0'),
            'stock' => post_int('stock'),
            'low_stock_alert' => post_int('low_stock_alert'),
            'condition' => (string) post('condition', 'new'),
            'status' => (string) post('status', 'draft'),
            'is_featured' => (bool) post('is_featured'),
            'is_flash_deal' => (bool) post('is_flash_deal'),
            'free_shipping' => (bool) post('free_shipping'),
            'shipping_fee' => (float) post('shipping_fee', '0'),
            'warranty' => (string) post('warranty', ''),
            'weight_grams' => post_int('weight_grams'),
            'image_url' => $imageUrls[0] ?? '',
            'gallery' => array_slice($imageUrls, 1, 19),
            'specs' => $specs,
            'tags' => $tags,
            'meta_title' => (string) post('meta_title', ''),
            'meta_description' => (string) post('meta_description', ''),
        ];
        try {
            $savedId = Catalog::saveProduct($data, $id > 0 ? $id : null);
        } catch (Throwable $e) {
            // Keep the input so nothing is lost, and make the failure findable in
            // the log (storage/logs/error-YYYYMMDD.log) without shouting SQL at staff.
            error_log('[smm] product_save failed (id ' . $id . '): ' . $e->getMessage());
            remember_input($_POST);
            $detail = $e instanceof PDOException && Config::get('app.debug')
                ? ' ' . $e->getMessage()
                : '';
            $done('danger', 'The product could not be saved.' . $detail
                . ' Your entries were kept — check storage/logs for details.');
        }
        $done('success', $id > 0 ? 'Product updated.' : 'Product created.');

    case 'product_delete':
        admin_require('products.manage');
        Catalog::deleteProduct(post_int('product_id'));
        $done('success', 'Product archived. Order history is unchanged.');

    case 'product_restore':
        admin_require('products.manage');
        Database::update('products', ['deleted_at' => null, 'status' => 'draft'], 'id = :id', ['id' => post_int('product_id')]);
        Audit::log('product.restored', 'product', post_int('product_id'));
        $done('success', 'Product restored as a draft.');

    case 'product_toggle':
        admin_require('products.manage');
        $product = Catalog::product(post_int('product_id'));
        if ($product === null) {
            $done('danger', 'Product not found.');
        }
        $field = (string) post('field');
        if (!in_array($field, ['is_featured', 'is_flash_deal', 'free_shipping'], true)) {
            $done('danger', 'Unknown product flag.');
        }
        Database::update('products', [$field => (int) !$product[$field]], 'id = :id', ['id' => (int) $product['id']]);
        $done('success', 'Product updated.');

    case 'category_save':
        admin_require('products.manage');
        $id = post_int('id');
        $data = [
            'parent_id' => post_int('parent_id'),
            'name' => (string) post('name', ''),
            'slug' => (string) post('slug', ''),
            'description' => (string) post('description', ''),
            'image_url' => (string) post('image_url', ''),
            'icon' => (string) post('icon', 'bi-grid'),
            'color' => (string) post('color', '#0c800f'),
            'position' => post_int('position'),
            'is_featured' => (bool) post('is_featured'),
            'is_active' => (bool) post('is_active'),
            'meta_title' => (string) post('meta_title', ''),
            'meta_description' => (string) post('meta_description', ''),
        ];
        try {
            if ($id > 0) {
                Catalog::updateCategory($id, $data);
            } else {
                Catalog::createCategory($data);
            }
        } catch (Throwable $e) {
            $done('danger', $e->getMessage());
        }
        $done('success', $id > 0 ? 'Category updated.' : 'Category created.');

    case 'category_delete':
        admin_require('products.manage');
        try {
            Catalog::deleteCategory(post_int('category_id'));
        } catch (Throwable $e) {
            $done('danger', $e->getMessage());
        }
        $done('success', 'Category deleted.');

    case 'brand_save':
        admin_require('products.manage');
        $id = post_int('id');
        $data = ['name' => (string) post('name', ''), 'slug' => (string) post('slug', ''), 'logo_url' => (string) post('logo_url', '')];
        try {
            if ($id > 0) {
                Catalog::updateBrand($id, $data);
            } else {
                Catalog::createBrand($data);
            }
        } catch (Throwable $e) {
            $done('danger', $e->getMessage());
        }
        $done('success', $id > 0 ? 'Brand updated.' : 'Brand created.');

    case 'brand_delete':
        admin_require('products.manage');
        try {
            Catalog::deleteBrand(post_int('brand_id'));
        } catch (Throwable $e) {
            $done('danger', $e->getMessage());
        }
        $done('success', 'Brand deleted.');

    case 'stock_adjust':
        admin_require('inventory.manage');
        $productId = post_int('product_id');
        $mode = (string) post('mode', 'delta');
        $reason = (string) post('reason', 'correction');
        $note = trim((string) post('note', ''));
        try {
            if ($mode === 'set') {
                Inventory::setStock($productId, post_int('quantity'), $reason, $note !== '' ? $note : null);
            } else {
                $delta = (int) post('quantity');
                if ($delta === 0) {
                    $done('warning', 'Enter a non-zero quantity.');
                }
                Inventory::adjust($productId, $delta, $reason, null, $note !== '' ? $note : null);
            }
        } catch (Throwable $e) {
            $done('danger', $e->getMessage());
        }
        $done('success', 'Stock updated.');

    case 'coupon_save':
        admin_require('coupons.manage');
        $id = post_int('id');
        $code = strtoupper(trim((string) post('code', '')));
        if ($code === '') {
            $done('warning', 'Enter a coupon code.');
        }
        if (Coupons::codeExists($code, $id > 0 ? $id : null)) {
            $done('danger', 'That coupon code already exists.');
        }
        Coupons::save([
            'code' => $code,
            'description' => (string) post('description', ''),
            'type' => (string) post('type', 'percent'),
            'value' => (float) post('value', '0'),
            'min_spend' => (float) post('min_spend', '0'),
            'max_discount' => (float) post('max_discount', '0'),
            'max_uses' => post_int('max_uses'),
            'per_user_limit' => post_int('per_user_limit'),
            'is_active' => (bool) post('is_active'),
            'starts_at' => (string) post('starts_at', ''),
            'expires_at' => (string) post('expires_at', ''),
            'applies_to_category_id' => post_int('applies_to_category_id'),
        ], $id > 0 ? $id : null);
        Audit::log('coupon.saved', 'coupon', $id);
        $done('success', $id > 0 ? 'Coupon updated.' : 'Coupon created.');

    case 'coupon_toggle':
        admin_require('coupons.manage');
        $coupon = Coupons::find(post_int('coupon_id'));
        if ($coupon === null) {
            $done('danger', 'Coupon not found.');
        }
        Coupons::save(['code' => $coupon['code'], 'description' => $coupon['description'], 'type' => $coupon['type'],
            'value' => $coupon['value'], 'min_spend' => $coupon['min_spend'], 'max_discount' => $coupon['max_discount'],
            'max_uses' => $coupon['max_uses'], 'per_user_limit' => $coupon['per_user_limit'],
            'is_active' => !(int) $coupon['is_active'], 'starts_at' => $coupon['starts_at'], 'expires_at' => $coupon['expires_at'],
            'applies_to_category_id' => $coupon['applies_to_category_id']], (int) $coupon['id']);
        $done('success', 'Coupon status changed.');

    case 'coupon_delete':
        admin_require('coupons.manage');
        Coupons::delete(post_int('coupon_id'));
        Audit::log('coupon.deleted', 'coupon', post_int('coupon_id'));
        $done('success', 'Coupon deleted.');

    /* ------------------------------------------------------------ reviews */
    case 'review_moderate':
        admin_require('reviews.manage');
        $reviewId = post_int('review_id');
        $decision = (string) post('decision', '');
        $review = Database::one('SELECT * FROM reviews WHERE id = :id', ['id' => $reviewId]);
        if ($review === null) {
            $done('danger', 'Review not found.');
        }
        $status = match ($decision) {
            'approve' => 'approved',
            'reject' => 'rejected',
            default => null,
        };
        if ($status === null) {
            $done('danger', 'Unknown moderation decision.');
        }
        Database::update('reviews', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $reviewId]);
        Catalog::refreshReviewStats((int) $review['product_id']);
        Audit::log('review.' . $status, 'review', $reviewId);
        if ($status === 'approved' && $review['user_id'] !== null) {
            Notifier::notify((int) $review['user_id'], 'Your review is live', 'Thanks for reviewing a product at ' . Settings::get('store_name') . '.', 'success', '/product.php?slug=' . (string) ($review['product_slug'] ?? ''));
        }
        $done('success', 'Review ' . $status . '.');

    case 'review_reply':
        admin_require('reviews.manage');
        $reviewId = post_int('review_id');
        $reply = trim((string) post('reply', ''));
        if ($reply === '') {
            $done('warning', 'Write a reply first.');
        }
        Database::update('reviews', [
            'admin_reply' => mb_substr($reply, 0, 1000),
            'admin_reply_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $reviewId]);
        Audit::log('review.replied', 'review', $reviewId);
        $done('success', 'Reply published.');

    case 'review_delete':
        admin_require('settings.manage');
        $review = Database::one('SELECT * FROM reviews WHERE id = :id', ['id' => post_int('review_id')]);
        if ($review !== null) {
            Database::run('DELETE FROM reviews WHERE id = :id', ['id' => (int) $review['id']]);
            Catalog::refreshReviewStats((int) $review['product_id']);
            Audit::log('review.deleted', 'review', (int) $review['id']);
        }
        $done('success', 'Review deleted.');

    /* ------------------------------------------------------------- people */
    case 'customer_status':
        admin_require('customers.manage');
        $targetId = post_int('user_id');
        $status = (string) post('status', '');
        if (!in_array($status, ['active', 'suspended'], true)) {
            $done('danger', 'Unknown status.');
        }
        if ($targetId === (int) Auth::id()) {
            $done('danger', 'You cannot change your own account status.');
        }
        Users::setStatus($targetId, $status);
        Audit::log('user.status_changed', 'user', $targetId, ['status' => $status]);
        $done('success', 'Customer account ' . $status . '.');

    case 'customer_role':
        admin_require('users.manage');
        $targetId = post_int('user_id');
        $role = (string) post('role', '');
        if (!in_array($role, ['admin', 'staff', 'customer'], true)) {
            $done('danger', 'Unknown role.');
        }
        if ($targetId === (int) Auth::id()) {
            $done('danger', 'You cannot change your own role.');
        }
        if ($role !== 'admin' && Users::isLastAdmin($targetId)) {
            $done('danger', 'Keep at least one administrator account.');
        }
        Users::update($targetId, ['role' => $role]);
        Audit::log('user.role_changed', 'user', $targetId, ['role' => $role]);
        $done('success', 'Role updated.');

    case 'team_save':
        admin_require('users.manage');
        $targetId = post_int('id');
        $name = trim((string) post('name', ''));
        $email = strtolower(trim((string) post('email', '')));
        $role = (string) post('role', 'staff');
        if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $done('danger', 'Enter a name and a valid email address.');
        }
        if (!in_array($role, ['admin', 'staff'], true)) {
            $done('danger', 'Choose a team role.');
        }
        if (Users::emailExists($email, $targetId > 0 ? $targetId : null)) {
            $done('danger', 'That email is already registered.');
        }
        try {
            if ($targetId > 0) {
                // Editing must not quietly un-suspend someone: the status only
                // changes through the explicit suspend/reactivate buttons.
                $existing = Users::find($targetId);
                if ($existing === null || !in_array((string) $existing['role'], ['admin', 'staff'], true)) {
                    $done('danger', 'That team member no longer exists.');
                }
                $status = $existing['status'] === 'suspended' ? 'suspended' : 'active';
                Users::update($targetId, ['name' => $name, 'email' => $email, 'role' => $role, 'status' => $status]);
                Audit::log('team.saved', 'user', $targetId);
                $done('success', 'Team member details saved.');
            }

            do {
                $temporaryPassword = Security::randomToken(18);
            } while (Auth::passwordErrors($temporaryPassword) !== []);
            $targetId = Users::create([
                'name' => $name,
                'email' => $email,
                'role' => $role,
                'status' => 'active',
                'email_verified_at' => date('Y-m-d H:i:s'),
                'must_change_password' => 1,
                'password_hash' => Auth::hashPassword($temporaryPassword),
            ]);
            $inviteUser = Users::find($targetId);
            if ($inviteUser === null || !Mailer::teamInvitation($inviteUser, $temporaryPassword)) {
                Users::purge($targetId);
                Audit::log('team.invitation_failed', 'user', null, ['email' => $email, 'role' => $role]);
                $done('danger', 'The invitation email could not be sent, so no team account was created. Check Admin → Settings → Email and try again.');
            }
            Audit::log('team.invited', 'user', $targetId, ['email' => $email, 'role' => $role]);
            $done('success', 'Invitation sent to ' . $email . '. The team member must change the temporary password at first sign-in.');
        } catch (Throwable $e) {
            error_log('Team account update failed: ' . $e->getMessage());
            $done('danger', 'The team account could not be saved. Check the email address and try again.');
        }

    case 'team_suspend':
        admin_require('users.manage');
        $targetId = post_int('user_id');
        if ($targetId === (int) Auth::id()) {
            $done('danger', 'You cannot suspend your own account.');
        }
        $target = Users::find($targetId);
        if ($target === null || !in_array((string) $target['role'], ['admin', 'staff'], true)) {
            $done('danger', 'That team member no longer exists.');
        }
        if ($target['status'] === 'suspended') {
            $done('info', 'That account is already suspended.');
        }
        if (Users::isLastAdmin($targetId)) {
            $done('danger', 'Keep at least one active administrator account.');
        }
        Users::setStatus($targetId, 'suspended');
        Audit::log('team.suspended', 'user', $targetId, ['email' => $target['email']]);
        $done('success', $target['name'] . ' can no longer sign in. Reactivate the account whenever you need it back.');

    case 'team_reactivate':
        admin_require('users.manage');
        $targetId = post_int('user_id');
        $target = Users::find($targetId);
        if ($target === null || !in_array((string) $target['role'], ['admin', 'staff'], true)) {
            $done('danger', 'That team member no longer exists.');
        }
        Users::setStatus($targetId, 'active');
        Audit::log('team.reactivated', 'user', $targetId, ['email' => $target['email']]);
        $done('success', $target['name'] . ' is active again and can sign in.');

    case 'team_delete':
        admin_require('users.manage');
        $targetId = post_int('user_id');
        if ($targetId === (int) Auth::id()) {
            $done('danger', 'You cannot delete your own account.');
        }
        $target = Users::find($targetId);
        if ($target === null || !in_array((string) $target['role'], ['admin', 'staff'], true)) {
            $done('danger', 'That team member no longer exists.');
        }
        // Deleting is permanent, so it must arrive from the button that says so.
        // Older forms (and anything that still posts plain team_delete) fall back
        // to the safe action instead of silently destroying the account.
        if ((string) post('confirm_delete', '') !== '1') {
            Users::setStatus($targetId, 'suspended');
            Audit::log('team.suspended', 'user', $targetId, ['email' => $target['email']]);
            $done('success', 'Team account suspended. Use Delete to remove it permanently.');
        }
        if (Users::isLastAdmin($targetId)) {
            $done('danger', 'Keep at least one administrator account.');
        }
        Users::purge($targetId);
        Audit::log('team.deleted', 'user', $targetId, ['email' => $target['email'], 'name' => $target['name']]);
        $done('success', $target['name'] . ' was deleted. Orders and audit history are unaffected.');

    /* ------------------------------------------------------------ settings */
    case 'settings_save':
        admin_require('settings.manage');
        $group = (string) post('group', 'general');
        $allowed = admin_setting_groups()[$group] ?? null;
        if ($allowed === null) {
            $done('danger', 'Unknown settings group.');
        }
        $values = [];
        foreach ($allowed as $key => $meta) {
            $raw = $_POST['settings'][$key] ?? null;
            if ($group === 'email' && $key === 'mail_smtp_password') {
                if (is_string($raw) && trim($raw) !== '') {
                    try {
                        $values['mail_smtp_password_enc'] = Security::encrypt($raw);
                    } catch (Throwable $e) {
                        error_log('SMTP password encryption failed: ' . $e->getMessage());
                        $done('danger', 'The SMTP password could not be secured. Check the application key and try again.');
                    }
                }
                continue;
            }
            if ($meta['type'] === 'bool') {
                $values[$key] = isset($_POST['settings'][$key]) ? '1' : '0';
                continue;
            }
            if ($raw === null) {
                continue;
            }
            $values[$key] = is_array($raw) ? (string) json_encode($raw, JSON_UNESCAPED_UNICODE) : trim((string) $raw);
            if (strlen($values[$key]) > 4000) {
                $done('danger', strtolower((string) $meta['label']) . ' is too long.');
            }
            if ($meta['type'] === 'number') {
                if (!is_numeric($values[$key])) {
                    $done('danger', 'Enter a valid number for ' . strtolower((string) $meta['label']) . '.');
                }
                $number = (float) $values[$key];
                if ($number < 0) {
                    $done('danger', strtolower((string) $meta['label']) . ' cannot be negative.');
                }
                if ($key === 'tax_percent' && $number > 100) {
                    $done('danger', 'Tax / VAT percent must be between 0 and 100.');
                }
                if ($key === 'live_tracking_refresh' && ($number < 5 || $number > 120)) {
                    $done('danger', 'Live tracking refresh must be between 5 and 120 seconds.');
                }
                if ($key === 'return_window_days' && ($number < 1 || $number > 90 || floor($number) !== $number)) {
                    $done('danger', 'The return request window must be a whole number from 1 to 90 days.');
                }
                if ($key === 'mail_smtp_port' && ((int) $number < 1 || (int) $number > 65535)) {
                    $done('danger', 'SMTP port must be between 1 and 65535.');
                }
                if ($key === 'mail_smtp_timeout' && ($number < 5 || $number > 60)) {
                    $done('danger', 'SMTP timeout must be between 5 and 60 seconds.');
                }
            }
            if ($meta['type'] === 'url' && $values[$key] !== '') {
                $parts = parse_url($values[$key]);
                if (!filter_var($values[$key], FILTER_VALIDATE_URL) || !is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
                    $done('danger', strtolower((string) $meta['label']) . ' must be a valid https URL.');
                }
            }
            if ($key === 'currency' && $values[$key] !== '') {
                if (!preg_match('/^[A-Za-z]{3}$/D', $values[$key])) {
                    $done('danger', 'Currency code must contain exactly three letters (for example LKR or USD).');
                }
                $values[$key] = strtoupper($values[$key]);
            }
            if ($key === 'store_country_code' && $values[$key] !== '') {
                if (!preg_match('/^[A-Za-z]{2}$/D', $values[$key])) {
                    $done('danger', 'Country code must contain two letters (ISO 3166-1 alpha-2).');
                }
                $values[$key] = strtoupper($values[$key]);
            }
            if ($key === 'store_language' && !preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $values[$key])) {
                $done('danger', 'Enter a valid language tag such as en-LK or si-LK.');
            }
            if ($key === 'google_analytics_id' && $values[$key] !== '' && !preg_match('/^(?:G-[A-Z0-9]+|UA-[0-9]+-[0-9]+)$/iD', $values[$key])) {
                $done('danger', 'Enter a valid Google Analytics measurement ID.');
            }
            if ($key === 'meta_pixel_id' && $values[$key] !== '' && !preg_match('/^[0-9]{5,30}$/D', $values[$key])) {
                $done('danger', 'Meta Pixel ID must contain 5 to 30 digits.');
            }
            if ($meta['type'] === 'color' && !preg_match('/^#[0-9a-f]{6}$/iD', $values[$key])) {
                $done('danger', 'Choose a valid six-digit hexadecimal colour.');
            }
            if ($meta['type'] === 'select' && !array_key_exists($values[$key], (array) ($meta['options'] ?? []))) {
                $done('danger', 'Choose a valid ' . strtolower((string) $meta['label']) . '.');
            }
            if ($meta['type'] === 'email' && $values[$key] !== '' && !filter_var($values[$key], FILTER_VALIDATE_EMAIL)) {
                $done('danger', 'Enter a valid email address for ' . strtolower((string) $meta['label']) . '.');
            }
            if ($group === 'email' && $key === 'mail_smtp_host' && $values[$key] !== ''
                && (!preg_match('/^[A-Za-z0-9.-]+$/D', $values[$key]) || strlen($values[$key]) > 253)) {
                $done('danger', 'Enter the SMTP hostname only, without a protocol, port, path or spaces.');
            }
            if ($group === 'email' && $key === 'mail_from_name' && preg_match('/[\x0A\x0D]/', $values[$key])) {
                $done('danger', 'The sender name cannot contain line breaks.');
            }
            if ($meta['type'] === 'image') {
                // Store one canonical shape: Drive share links become
                // file/d/ID/view, plain https URLs are kept, junk is dropped.
                $values[$key] = (string) (normalize_image_url($values[$key]) ?? '');
            }
        }
        Settings::setMany($values);
        Audit::log('settings.updated', 'settings', null, ['group' => $group]);
        $done('success', 'Settings saved.');

    case 'email_test':
        admin_require('settings.manage');
        $admin = Auth::user();
        $sent = $admin !== null && Mailer::send(
            (string) $admin['email'],
            Settings::get('store_name') . ' email configuration test',
            Mailer::wrap('Email configuration test', '<p>Your configured transactional email transport can deliver messages to this administrator address.</p>')
        );
        if ($sent) {
            Audit::log('settings.email_test_sent', 'settings', null, ['to' => (string) $admin['email']]);
            $done('success', 'Test email sent to ' . (string) $admin['email'] . '. Check your inbox and spam folder.');
        }
        $done('danger', 'Test email could not be delivered. Check the SMTP/server mail settings and application logs.');

    case 'hero_slides_save':
        admin_require('settings.manage');
        // Slides ticked for removal are skipped; blank headlines are dropped too,
        // so an "Add slide" card left empty never reaches the storefront.
        $removed = array_map('intval', array_values((array) ($_POST['slide_delete'] ?? [])));
        $images = (array) ($_POST['slide_image'] ?? []);
        $slides = [];
        foreach ((array) ($_POST['slide_title'] ?? []) as $index => $title) {
            if (in_array((int) $index, $removed, true)) {
                continue;
            }
            $title = trim((string) $title);
            if ($title === '') {
                continue;
            }
            // Same link rules as product images: Drive share links are folded
            // into the canonical file/d/ID/view shape, plain https URLs are kept.
            $image = normalize_image_url((string) ($images[$index] ?? ''));
            $slides[] = [
                'eyebrow' => mb_substr(trim((string) ($_POST['slide_eyebrow'][$index] ?? '')), 0, 60),
                'title' => mb_substr($title, 0, 120),
                'highlight' => mb_substr(trim((string) ($_POST['slide_highlight'][$index] ?? '')), 0, 120),
                'description' => mb_substr(trim((string) ($_POST['slide_description'][$index] ?? '')), 0, 400),
                'cta_label' => mb_substr(trim((string) ($_POST['slide_cta_label'][$index] ?? '')) ?: 'Shop now', 0, 40),
                'cta_url' => mb_substr(trim((string) ($_POST['slide_cta_url'][$index] ?? '')) ?: '/shop.php', 0, 300),
                'secondary_label' => mb_substr(trim((string) ($_POST['slide_secondary_label'][$index] ?? '')), 0, 40),
                'secondary_url' => mb_substr(trim((string) ($_POST['slide_secondary_url'][$index] ?? '')), 0, 300),
                'theme' => in_array($_POST['slide_theme'][$index] ?? '', ['green', 'blue', 'light'], true) ? (string) $_POST['slide_theme'][$index] : 'green',
                'image' => (string) ($image ?? ''),
            ];
            if (count($slides) >= View::HERO_SLIDE_LIMIT) {
                break;
            }
        }
        Settings::set('hero_slides', $slides === [] ? '' : (string) json_encode($slides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Audit::log('settings.hero_slides', 'settings', null, ['slides' => count($slides)]);
        $done('success', $slides === [] ? 'All custom slides removed — the built-in slides are showing again.' : 'Hero slides saved (' . count($slides) . ').');

    /* ------------------------------------------------------------ messages */
    case 'message_read':
        admin_require('messages.view');
        Database::update('contact_messages', ['status' => 'open'], 'id = :id', ['id' => post_int('message_id')]);
        $done('success', 'Message marked as read.');

    case 'message_delete':
        admin_require('settings.manage');
        Database::run('DELETE FROM contact_messages WHERE id = :id', ['id' => post_int('message_id')]);
        $done('success', 'Message deleted.');

    case 'subscriber_delete':
        admin_require('settings.manage');
        Database::run('DELETE FROM newsletter_subscribers WHERE id = :id', ['id' => post_int('subscriber_id')]);
        $done('success', 'Subscriber removed.');

    case 'gateway_save':
        admin_require('settings.manage');
        $stored = json_decode(Settings::get('gateway_config', ''), true);
        $stored = is_array($stored) ? $stored : [];
        $effective = admin_gateway_config();
        foreach (admin_gateway_fields() as $gatewayKey => $gatewayMeta) {
            $current = (array) ($effective[$gatewayKey] ?? []);
            $values = [];
            foreach ($gatewayMeta['fields'] as $fieldKey => $fieldMeta) {
                $posted = $_POST['gateway'][$gatewayKey][$fieldKey] ?? null;
                if ($fieldMeta['type'] === 'bool') {
                    $values[$fieldKey] = isset($_POST['gateway'][$gatewayKey][$fieldKey]) ? true : false;
                    continue;
                }
                if ($posted === null) {
                    continue;
                }
                if ($fieldMeta['type'] === 'select'
                    && !array_key_exists(trim((string) $posted), (array) ($fieldMeta['options'] ?? []))) {
                    $done('danger', 'Choose a valid ' . strtolower((string) $fieldMeta['label']) . ' option.');
                }
                $values[$fieldKey] = $fieldMeta['type'] === 'number' ? (float) $posted : trim((string) $posted);
                // Never blank out a stored secret by submitting the masked placeholder.
                if ($fieldMeta['type'] === 'password' && $values[$fieldKey] === '') {
                    unset($values[$fieldKey]);
                }
            }
            // Bank accounts are edited as a small repeating list.
            if ($gatewayKey === 'bank_transfer') {
                $accounts = [];
                $banks = (array) ($_POST['bank']['bank'] ?? []);
                $numbers = (array) ($_POST['bank']['number'] ?? []);
                $names = (array) ($_POST['bank']['name'] ?? []);
                $branches = (array) ($_POST['bank']['branch'] ?? []);
                foreach ($banks as $index => $bankName) {
                    $bankName = trim((string) $bankName);
                    $number = trim((string) ($numbers[$index] ?? ''));
                    if ($bankName === '' || $number === '') {
                        continue;
                    }
                    $accounts[] = [
                        'bank' => $bankName,
                        'branch' => trim((string) ($branches[$index] ?? '')),
                        'name' => trim((string) ($names[$index] ?? '')),
                        'number' => $number,
                    ];
                }
                $values['accounts'] = $accounts;
            }
            $stored[$gatewayKey] = array_merge($current, $values);
        }
        Settings::set('gateway_config', (string) json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Audit::log('settings.gateways', 'settings');
        $done('success', 'Payment methods updated.');

    /* ------------------------------------------------------------ tools */
    case 'installer_lock':
        admin_require('settings.manage');
        $lockPath = STORAGE_PATH . '/installed.lock';
        if (is_file($lockPath)) {
            $done('info', 'The installer is already locked.');
        }
        $written = @file_put_contents($lockPath, (string) json_encode([
            'installed_at' => date('c'),
            'version' => SMM_VERSION,
            'locked_by' => (int) Auth::id(),
        ], JSON_PRETTY_PRINT));
        if ($written === false) {
            $done('danger', 'Could not write storage/installed.lock. Create the file manually or chmod storage/ to 775.');
        }
        @chmod($lockPath, 0640);
        Audit::log('installer.locked', 'system');
        $done('success', 'Installer locked. /install/ can no longer change this database.');

    case 'seed_demo':
        // Web seeder for hosts without SSH. Bounded so it cannot hit the
        // free plan's execution limit, and off by default for staff.
        admin_require('settings.manage');
        $productsWanted = min(2000, max(0, post_int('products')));
        $customersWanted = min(2000, max(0, post_int('customers')));
        $ordersWanted = min(200, max(0, post_int('orders')));
        if ($productsWanted + $customersWanted + $ordersWanted === 0) {
            $done('warning', 'Enter at least one count to generate.');
        }
        @set_time_limit(180);
        ignore_user_abort(true);

        // The seeder is written for the CLI; allow it to be included here.
        if (!defined('SMM_SEED_ALLOW_WEB')) {
            define('SMM_SEED_ALLOW_WEB', true);
        }
        require_once ROOT_PATH . '/tools/seed.php';

        $summary = [];
        try {
            $categoryIds = seed_catalog_tree();
            $brandMap = seed_catalog_brands();
            seed_catalog_coupons();
            ob_start();
            if ($productsWanted > 0) {
                $summary[] = number_format(seed_products($productsWanted, $categoryIds, $brandMap)) . ' products';
            }
            if ($customersWanted > 0) {
                $summary[] = number_format(seed_customers($customersWanted)) . ' customers';
            }
            if ($ordersWanted > 0) {
                $summary[] = number_format(seed_orders($ordersWanted)) . ' orders';
            }
            ob_end_clean();
        } catch (Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            error_log('Web seeding failed: ' . $e->getMessage());
            Audit::log('tools.seed_failed', 'system', null, ['error' => $e->getMessage()]);
            $done('danger', 'Seeding stopped: ' . $e->getMessage() . ' — lower the counts and try again.');
        }

        Audit::log('tools.seed', 'system', null, [
            'products' => $productsWanted,
            'customers' => $customersWanted,
            'orders' => $ordersWanted,
        ]);
        $done('success', 'Generated ' . implode(', ', $summary) . '.');

    default:
        $done('danger', 'Unknown action.');
}

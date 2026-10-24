<?php
/**
 * Dashboard write endpoint.
 *
 * One CSRF-protected dispatcher keeps every mutation in a single auditable
 * place: each branch checks its own permission, validates input and redirects
 * back to the page that posted the form.
 */
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

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

    /* ---------------------------------------------------------- catalogue */
    case 'product_save':
        admin_require('products.manage');
        $id = post_int('id');
        $gallery = array_values(array_filter(array_map('trim', (array) ($_POST['gallery'] ?? []))));
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
            'image_url' => (string) post('image_url', ''),
            'gallery' => $gallery,
            'specs' => $specs,
            'tags' => $tags,
            'meta_title' => (string) post('meta_title', ''),
            'meta_description' => (string) post('meta_description', ''),
        ];
        try {
            $savedId = Catalog::saveProduct($data, $id > 0 ? $id : null);
        } catch (Throwable $e) {
            remember_input($_POST);
            $done('danger', $e->getMessage());
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
        $password = (string) post('password', '');
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
                $fields = ['name' => $name, 'email' => $email, 'role' => $role, 'status' => 'active'];
                if ($password !== '') {
                    $errors = Auth::passwordErrors($password);
                    if ($errors !== []) {
                        $done('danger', implode(' ', $errors));
                    }
                    $fields['password_hash'] = Auth::hashPassword($password);
                }
                Users::update($targetId, $fields);
            } else {
                $errors = Auth::passwordErrors($password);
                if ($errors !== []) {
                    $done('danger', implode(' ', $errors));
                }
                Users::create([
                    'name' => $name, 'email' => $email, 'role' => $role,
                    'password_hash' => Auth::hashPassword($password), 'status' => 'active',
                ]);
            }
        } catch (Throwable $e) {
            $done('danger', $e->getMessage());
        }
        Audit::log('team.saved', 'user', $targetId);
        $done('success', 'Team member saved.');

    case 'team_delete':
        admin_require('users.manage');
        $targetId = post_int('user_id');
        if ($targetId === (int) Auth::id()) {
            $done('danger', 'You cannot remove your own account.');
        }
        if (Users::isLastAdmin($targetId)) {
            $done('danger', 'Keep at least one administrator account.');
        }
        Users::setStatus($targetId, 'suspended');
        Audit::log('team.suspended', 'user', $targetId);
        $done('success', 'Team account suspended.');

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
            if ($meta['type'] === 'bool') {
                $values[$key] = isset($_POST['settings'][$key]) ? '1' : '0';
                continue;
            }
            if ($raw === null) {
                continue;
            }
            $values[$key] = is_array($raw) ? (string) json_encode($raw, JSON_UNESCAPED_UNICODE) : trim((string) $raw);
            if ($meta['type'] === 'number' && !is_numeric($values[$key])) {
                $values[$key] = (string) $meta['default'];
            }
        }
        Settings::setMany($values);
        Audit::log('settings.updated', 'settings', null, ['group' => $group]);
        $done('success', 'Settings saved.');

    case 'hero_slides_save':
        admin_require('settings.manage');
        $slides = [];
        $titles = (array) ($_POST['slide_title'] ?? []);
        foreach ($titles as $index => $title) {
            $title = trim((string) $title);
            if ($title === '') {
                continue;
            }
            $slides[] = [
                'eyebrow' => trim((string) ($_POST['slide_eyebrow'][$index] ?? '')),
                'title' => $title,
                'highlight' => trim((string) ($_POST['slide_highlight'][$index] ?? '')),
                'description' => trim((string) ($_POST['slide_description'][$index] ?? '')),
                'cta_label' => trim((string) ($_POST['slide_cta_label'][$index] ?? 'Shop now')),
                'cta_url' => trim((string) ($_POST['slide_cta_url'][$index] ?? '/shop.php')),
                'secondary_label' => trim((string) ($_POST['slide_secondary_label'][$index] ?? '')),
                'secondary_url' => trim((string) ($_POST['slide_secondary_url'][$index] ?? '')),
                'theme' => in_array($_POST['slide_theme'][$index] ?? '', ['green', 'blue', 'light'], true) ? (string) $_POST['slide_theme'][$index] : 'green',
                'icon' => trim((string) ($_POST['slide_icon'][$index] ?? 'bi-bag-heart')),
            ];
        }
        Settings::set('hero_slides', $slides === [] ? '' : (string) json_encode($slides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Audit::log('settings.hero_slides', 'settings');
        $done('success', 'Hero slides saved.');

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

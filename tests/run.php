<?php
/**
 * YourMarket test runner.
 *
 *   php tests/run.php            # fast, read-only unit tests (no database writes)
 *   php tests/run.php --db       # also run database-backed checks
 *   php tests/run.php --filter=pricing
 *
 * The suite never writes to the database: every check is a read or a pure
 * function call, so it is safe to run against a live store.
 *
 * Exit code 0 = all green, 1 = at least one failure.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('The test runner can only be used from the command line.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/maintenance.php';

/* ------------------------------------------------------------------ harness */

/** Thrown by T::skip() so a case can opt out without failing. */
final class SkippedCase extends RuntimeException
{
}

final class T
{
    public static int $passed = 0;
    /** @var list<string> */
    public static array $failures = [];
    /** @var list<string> */
    public static array $skipped = [];
    public static string $current = '';
    public static string $filter = '';

    public static function case(string $name, callable $body): void
    {
        if (self::$filter !== '' && stripos($name, self::$filter) === false) {
            return;
        }
        self::$current = $name;
        try {
            $body();
            self::$passed++;
            echo "  \033[32m✓\033[0m {$name}\n";
        } catch (SkippedCase) {
            // already reported by skip()
        } catch (Throwable $e) {
            self::$failures[] = $name . ' — ' . $e->getMessage();
            echo "  \033[31m✗\033[0m {$name}\n    " . $e->getMessage() . "\n";
        }
    }

    /** Skip a case whose PHP extension is missing on this host. */
    public static function skip(string $reason): never
    {
        self::$skipped[] = self::$current . ' — ' . $reason;
        echo "  \033[33m·\033[0m {$reason}\n";
        throw new SkippedCase($reason);
    }

    public static function same(mixed $expected, mixed $actual, string $what = ''): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(sprintf(
                '%sexpected %s, got %s',
                $what !== '' ? $what . ': ' : '',
                var_export($expected, true),
                var_export($actual, true)
            ));
        }
    }

    public static function true(bool $value, string $what = ''): void
    {
        if (!$value) {
            throw new RuntimeException($what !== '' ? $what : 'expected true');
        }
    }

    public static function false(bool $value, string $what = ''): void
    {
        if ($value) {
            throw new RuntimeException($what !== '' ? $what : 'expected false');
        }
    }

    public static function contains(string $needle, string $haystack, string $what = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new RuntimeException(sprintf('%sexpected to find "%s" in "%s"', $what !== '' ? $what . ': ' : '', $needle, $haystack));
        }
    }

    public static function summary(): int
    {
        $failed = count(self::$failures);
        echo "\n" . str_repeat('-', 60) . "\n";
        $skipped = count(self::$skipped);
        if ($failed === 0) {
            echo "\033[32mAll " . self::$passed . " checks passed"
                . ($skipped > 0 ? " ({$skipped} skipped)" : '') . ".\033[0m\n";
            return 0;
        }
        echo "\033[31m{$failed} failed\033[0m, " . self::$passed . " passed:\n";
        foreach (self::$failures as $failure) {
            echo '  - ' . $failure . "\n";
        }
        return 1;
    }
}

$options = getopt('', ['db', 'filter::']);
$withDb = array_key_exists('db', $options);
T::$filter = (string) ($options['filter'] ?? '');

echo "YourMarket test suite — PHP " . PHP_VERSION . "\n";
echo 'Mode: ' . ($withDb ? "unit + database" : 'unit only') . "\n\n";

/* ------------------------------------------------------------------ helpers */

T::case('helpers: slugify strips punctuation and lowercases', function (): void {
    T::same('samsung-galaxy-s24-ultra', slugify('  Samsung Galaxy S24 Ultra! '));
    T::same('galaxy-s24-5g', slugify('Galaxy S24 / 5G'));
    T::true(str_starts_with(slugify('!!!'), 'item-'), 'fallback slug for punctuation-only input');
});

T::case('helpers: money formats LKR without trailing zeros', function (): void {
    T::same('Rs 1,250', money(1250));
    T::same('Rs 1,250.50', money('1250.5'));
    T::same('Rs 0', money(null));
    T::same('1,250', money(1250, false));
});

T::case('helpers: compact_number abbreviates thousands and millions', function (): void {
    T::same('999', compact_number(999));
    T::same('1.2k', compact_number(1234));
    T::same('2M', compact_number(2_000_000));
});

T::case('helpers: limit_text truncates with an ellipsis', function (): void {
    T::same('short', limit_text('short', 20));
    T::same('abcd…', limit_text('abcdefghij', 5));
});

T::case('helpers: initials', function (): void {
    T::same('KP', initials('Kasun Perera'));
    T::same('A', initials('amal'));
});

T::case('helpers: phone normalisation accepts local and +94 formats', function (): void {
    T::same('0771234567', normalize_phone('0771234567'));
    T::same('0771234567', normalize_phone('+94 77 123 4567'));
    T::same('0771234567', normalize_phone('94771234567'));
    T::same('0771234567', normalize_phone('771234567'));
    T::same('077 123 4567', phone_display('0771234567'));
    T::true(is_valid_phone('0771234567'));
    T::false(is_valid_phone('12345'));
    T::false(is_valid_phone(''));
});

T::case('helpers: drive_file_id reads every Google Drive link shape', function (): void {
    $id = '1a2B3c4D5e6F7g8H9i0JkLmNoPqRsTuVw';
    T::same($id, drive_file_id('https://drive.google.com/file/d/' . $id . '/view?usp=sharing'));
    T::same($id, drive_file_id('https://drive.google.com/open?id=' . $id));
    T::same($id, drive_file_id('https://drive.google.com/uc?export=download&id=' . $id));
    T::same($id, drive_file_id('https://docs.google.com/uc?id=' . $id . '&export=download'));
    T::same($id, drive_file_id($id));
    T::same(null, drive_file_id('https://example.com/nope.jpg'));
    T::same(null, drive_file_id(''));
});

T::case('helpers: img_url proxies Drive links and keeps https URLs', function (): void {
    $drive = 'https://drive.google.com/file/d/1a2B3c4D5e6F7g8H9i0JkLmNoPqRsTuVw/view';
    T::same('/image.php?src=' . rawurlencode($drive), img_url($drive));
    T::same('https://cdn.example.com/a.jpg', img_url('https://cdn.example.com/a.jpg'));
    T::same('/assets/img/logo.png', img_url('/assets/img/logo.png'));
    T::contains('/image.php?placeholder=1', img_url(''));
});

T::case('helpers: paginate clamps pages and computes offsets', function (): void {
    $pager = paginate(100, 1, 24);
    T::same(1, $pager['page']);
    T::same(5, $pager['pages']);
    T::same(0, $pager['offset']);
    T::same(1, $pager['from']);
    T::same(24, $pager['to']);
    T::false($pager['hasPrev']);
    T::true($pager['hasNext']);

    $clamped = paginate(100, 99, 24);
    T::same(5, $clamped['page']);
    T::false($clamped['hasNext']);
    T::same(100, $clamped['to']);

    $empty = paginate(0, 1, 24);
    T::same(0, $empty['from']);
    T::same(0, $empty['to']);
    T::same(1, $empty['pages']);
});

T::case('helpers: order and payment status labels are complete', function (): void {
    foreach (['PENDING', 'CONFIRMED', 'PROCESSING', 'PACKED', 'SHIPPED', 'OUT_FOR_DELIVERY', 'DELIVERED', 'CANCELLED', 'REFUNDED'] as $status) {
        $label = order_status_label($status);
        T::true($label !== '' && $label !== $status, "missing label for {$status}");
    }
    T::contains('status-badge', order_status_badge('DELIVERED'));
    T::true(payment_status_label('UNPAID') !== '');
    T::true(payment_status_label('PAID') !== '');
});

T::case('helpers: uuid v4 shape and uniqueness', function (): void {
    $a = uuid();
    $b = uuid();
    T::true((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $a), 'uuid shape: ' . $a);
    T::true($a !== $b, 'uuids must differ');
});

T::case('helpers: build_tree nests children by parent id', function (): void {
    $rows = [
        ['id' => 1, 'parent_id' => null, 'name' => 'Root'],
        ['id' => 2, 'parent_id' => 1, 'name' => 'Child'],
        ['id' => 3, 'parent_id' => 2, 'name' => 'Grandchild'],
    ];
    $tree = build_tree($rows, null);
    T::same(1, count($tree));
    T::same('Child', $tree[0]['children'][0]['name']);
    T::same('Grandchild', $tree[0]['children'][0]['children'][0]['name']);
});

T::case('helpers: e() escapes once and e_nl2br keeps line breaks', function (): void {
    T::same('&lt;b&gt;hi&lt;/b&gt;', e('<b>hi</b>'));
    T::same('&amp;amp;', e('&amp;'));
    T::contains('<br', e_nl2br("a\nb"));
});

/* ----------------------------------------------------------------- security */

T::case('security: signed tokens round-trip and reject tampering', function (): void {
    $token = Security::sign(['uid' => 42, 'scope' => 'reset']);
    $payload = Security::verify($token);
    T::true(is_array($payload), 'token should verify');
    T::same(42, $payload['uid']);
    T::same('reset', $payload['scope']);
    T::same(null, Security::verify($token . 'x'), 'tampered token must fail');
    T::same(null, Security::verify('garbage'), 'garbage token must fail');
});

T::case('security: expired tokens are rejected', function (): void {
    $token = Security::sign(['uid' => 1], -10);
    T::same(null, Security::verify($token));
});

T::case('security: encrypt/decrypt round-trip and random IV', function (): void {
    if (!function_exists('openssl_encrypt')) {
        T::skip('openssl extension not available — Security::encrypt() is unused at runtime');
    }
    $plain = 'Seylan secret 1234';
    $one = Security::encrypt($plain);
    $two = Security::encrypt($plain);
    T::true($one !== $two, 'ciphertext must be randomised');
    T::same($plain, Security::decrypt($one));
    T::same(null, Security::decrypt(substr($one, 0, -4)));
});

T::case('security: password hashing uses bcrypt and verifies', function (): void {
    $hash = Auth::hashPassword('Password123!');
    T::true(password_verify('Password123!', $hash), 'hash should verify');
    T::false(password_verify('wrong', $hash), 'wrong password must fail');
    T::true(password_get_info($hash)['algo'] !== 0, 'expected a real algorithm');
});

T::case('security: password policy rejects weak passwords', function (): void {
    T::true(count(Auth::passwordErrors('short')) > 0, 'short password must fail');
    T::same([], Auth::passwordErrors('Password123!'));
});

T::case('security: constant-time comparison', function (): void {
    T::true(Security::equals('abc', 'abc'));
    T::false(Security::equals('abc', 'abd'));
    T::false(Security::equals(null, ''));
});

T::case('security: role permission matrix', function (): void {
    $constant = (new ReflectionClass('Auth'))->getReflectionConstant('PERMISSIONS');
    T::true($constant !== false, 'PERMISSIONS constant is missing');
    $matrix = $constant->getValue();

    T::same(['*'], $matrix['admin'], 'admin should be unrestricted');
    T::true(in_array('orders.manage', $matrix['staff'], true), 'staff cannot manage orders');
    T::true(in_array('products.manage', $matrix['staff'], true), 'staff cannot manage products');
    T::false(in_array('users.manage', $matrix['staff'], true), 'staff must not manage the team');
    T::false(in_array('settings.manage', $matrix['staff'], true), 'staff must not change settings');
    T::same(['account.self'], $matrix['customer'], 'customers must only have account.self');
});

/* ---------------------------------------------------------------- validator */

T::case('validator: required, email and length rules', function (): void {
    $result = Validator::make(
        ['name' => 'Amal', 'email' => 'amal@example.lk'],
        ['name' => 'required|string|max:120', 'email' => 'required|email']
    )->validate();
    T::true($result['valid'], 'valid payload: ' . json_encode($result['errors']));
    T::same('amal@example.lk', $result['data']['email']);

    $bad = Validator::make(['email' => 'not-an-email'], ['email' => 'required|email'])->validate();
    T::false($bad['valid']);
    T::true(isset($bad['errors']['email']));

    $missing = Validator::make([], ['name' => 'required'])->validate();
    T::false($missing['valid']);
});

T::case('validator: numeric rules fire after casts', function (): void {
    $result = Validator::make(
        ['price' => '1500', 'stock' => '12', 'note' => 'x'],
        ['price' => 'required|numeric|min:1', 'stock' => 'required|int|min:0']
    )->validate();
    T::true($result['valid'], 'errors: ' . json_encode($result['errors']));

    $neg = Validator::make(['price' => '-5'], ['price' => 'required|numeric|min:0'])->validate();
    T::false($neg['valid'], 'negative price must fail min rule');
});

T::case('validator: unknown keys are dropped from clean data', function (): void {
    $result = Validator::make(['code' => 'WELCOME10', 'is_admin' => '1'], ['code' => 'required|string'])->validate();
    T::true($result['valid']);
    T::true(!array_key_exists('is_admin', $result['data']), 'mass-assignment key must be stripped');
});

/* ----------------------------------------------------------------- payments */

T::case('payments: method code mapping covers every gateway', function (): void {
    T::same('CARD_SEYLAN', PaymentManager::methodCode('seylan'));
    T::same('CARD_SEYLAN', PaymentManager::methodCode('card'));
    T::same('PAYHERE', PaymentManager::methodCode('payhere'));
    T::same('BANK_TRANSFER', PaymentManager::methodCode('bank'));
    T::same('BANK_TRANSFER', PaymentManager::methodCode('bank_transfer'));
    T::same('KOKO', PaymentManager::methodCode('koko'));
    T::same('PAYZY', PaymentManager::methodCode('payzy'));
    T::same('MINTPAY', PaymentManager::methodCode('mintpay'));
    T::same('COD', PaymentManager::methodCode('cod'));
});

T::case('payments: reverse mapping', function (): void {
    T::same('seylan', PaymentManager::keyForMethod('CARD_SEYLAN'));
    T::same('payhere', PaymentManager::keyForMethod('PAYHERE'));
    T::same('bank_transfer', PaymentManager::keyForMethod('BANK_TRANSFER'));
    T::same('cod', PaymentManager::keyForMethod('COD'));
    T::same('koko', PaymentManager::keyForMethod('KOKO'));
});

T::case('payments: CSP allows the PayHere hosted checkout form submission', function (): void {
    $security = (string) file_get_contents(dirname(__DIR__) . '/includes/Security.php');
    T::contains("form-action 'self' https://www.payhere.lk https://sandbox.payhere.lk", $security, 'PayHere live and sandbox form destinations');
    T::contains("header('Referrer-Policy: strict-origin-when-cross-origin')", $security, 'PayHere receives the cross-origin Referer origin');
});

T::case('payments: PayHere checkout fields and signature are generated correctly', function (): void {
    $gateway = new PayHereGateway('payhere', [
        'merchant_id' => '123456', 'merchant_secret' => 'test-secret',
        'mode' => 'sandbox', 'simulate' => false, 'currency' => 'LKR',
    ]);
    $order = [
        'id' => 25, 'order_number' => 'SMM-20260301-ABC123', 'total' => 1250.5,
        'currency' => 'LKR', 'customer_name' => 'Ada Lovelace', 'email' => 'ada@example.test',
        'phone' => '+94770000000',
        'shipping_address_array' => ['full_name' => 'Ada Lovelace 🛒', 'line1' => '1 Main Street <script>alert(1)</script>', 'line2' => 'Unit 2', 'city' => 'Colombo', 'phone' => '077 000 0000'],
    ];
    $intent = $gateway->initiate($order, ['gateway_reference' => '']);
    T::same('https://sandbox.payhere.lk/pay/checkout', $intent['url']);
    T::same('POST', $intent['method']);
    $payload = $intent['params'];
    foreach (['merchant_id', 'return_url', 'cancel_url', 'notify_url', 'order_id', 'items', 'currency', 'amount', 'first_name', 'last_name', 'email', 'phone', 'address', 'city', 'country', 'delivery_address', 'delivery_city', 'delivery_country', 'hash'] as $required) {
        T::true(isset($payload[$required]) && $payload[$required] !== '', 'missing required PayHere field: ' . $required);
    }
    T::same('123456', $payload['merchant_id']);
    T::same('1250.50', $payload['amount']);
    T::same('LKR', $payload['currency']);
    T::same('Ada', $payload['first_name']);
    T::same('Lovelace', $payload['last_name']);
    T::same('+94770000000', $payload['phone']);
    T::true(strpos($payload['address'], '<') === false && strpos($payload['address'], 'alert') === false, 'script content must not be sent to PayHere');
    T::same($gateway->sign($payload), $payload['hash'], 'PayHere hash must cover the exact POST amount and currency');
});

T::case('payments: PayHere refuses invalid required contact data', function (): void {
    $gateway = new PayHereGateway('payhere', [
        'merchant_id' => '123456', 'merchant_secret' => 'test-secret', 'mode' => 'sandbox', 'simulate' => false,
    ]);
    $baseOrder = [
        'id' => 1, 'order_number' => 'SMM-CONTACT-1', 'total' => 100, 'currency' => 'LKR',
        'customer_name' => 'Test Buyer', 'email' => 'not-an-email', 'phone' => 'invalid',
        'shipping_address_array' => ['full_name' => 'Test Buyer', 'line1' => '1 Road', 'city' => 'Colombo', 'phone' => 'invalid'],
    ];
    try {
        $gateway->initiate($baseOrder, []);
        throw new RuntimeException('Invalid email should be rejected.');
    } catch (InvalidArgumentException $error) {
        T::contains('valid email address', $error->getMessage());
    }
    $baseOrder['email'] = 'test@example.test';
    try {
        $gateway->initiate($baseOrder, []);
        throw new RuntimeException('Invalid phone should be rejected.');
    } catch (InvalidArgumentException $error) {
        T::contains('valid Sri Lankan mobile number', $error->getMessage());
    }
});

T::case('payments: PayHere live mode uses live endpoint and never falls back to simulation', function (): void {
    $gateway = new PayHereGateway('payhere', [
        'merchant_id' => '123456', 'merchant_secret' => 'live-secret',
        'mode' => 'live', 'simulate' => true, 'live_url' => 'https://www.payhere.lk/wrong/action',
    ]);
    $order = ['id' => 1, 'order_number' => 'SMM-LIVE-1', 'total' => 100, 'currency' => 'LKR'];
    $intent = $gateway->initiate($order, []);
    T::same('https://www.payhere.lk/pay/checkout', $intent['url']);
    T::same(false, $intent['simulated']);

    $missingCredentials = new PayHereGateway('payhere', ['mode' => 'live', 'simulate' => true]);
    try {
        $missingCredentials->initiate($order, []);
        throw new RuntimeException('Live checkout must fail closed without credentials.');
    } catch (RuntimeException $error) {
        T::contains('credentials are required', $error->getMessage());
    }
});

T::case('payments: invalid PayHere mode is rejected', function (): void {
    $gateway = new PayHereGateway('payhere', ['mode' => 'other', 'merchant_id' => '1', 'merchant_secret' => 'secret']);
    try {
        $gateway->initiate(['id' => 1, 'order_number' => 'SMM-1', 'total' => 10, 'currency' => 'LKR'], []);
        throw new RuntimeException('Unknown mode must be rejected.');
    } catch (RuntimeException $error) {
        T::contains('mode must be set', $error->getMessage());
    }
});

T::case('payments: PayHere callback signature and order amount are verified', function (): void {
    $gateway = new PayHereGateway('payhere', ['merchant_id' => '123456', 'merchant_secret' => 'test-secret']);
    $payload = [
        'merchant_id' => '123456', 'order_id' => 'SMM-20260301-ABC123',
        'payhere_amount' => '1250.50', 'payhere_currency' => 'LKR', 'status_code' => '2', 'payment_id' => '9988',
    ];
    $payload['md5sig'] = strtoupper(md5(
        $payload['merchant_id'] . $payload['order_id'] . $payload['payhere_amount']
        . $payload['payhere_currency'] . $payload['status_code'] . strtoupper(md5('test-secret'))
    ));
    $order = ['order_number' => 'SMM-20260301-ABC123', 'total' => 1250.50, 'currency' => 'LKR', 'payment_method' => 'PAYHERE'];
    T::true($gateway->verifyCallback($payload), 'signed callback should verify');
    T::true($gateway->matchesOrder($payload, $order), 'signed callback matches order');
    T::same('paid', $gateway->handleCallback($payload)['status']);
    $forged = $payload;
    $forged['md5sig'] = str_repeat('0', 32);
    T::false($gateway->verifyCallback($forged), 'an unsigned third-party payment notification must be rejected');
    $wrongMerchant = $payload;
    $wrongMerchant['merchant_id'] = '654321';
    T::false($gateway->verifyCallback($wrongMerchant), 'a callback for another merchant must be rejected');
    $tampered = $payload;
    $tampered['payhere_amount'] = '1250.51';
    T::false($gateway->matchesOrder($tampered, $order), 'amount tampering must fail');
    $wrongOrder = $payload;
    $wrongOrder['order_id'] = 'SMM-OTHER';
    T::false($gateway->matchesOrder($wrongOrder, $order), 'order id tampering must fail');
});

T::case('payments: all seven gateways are registered', function (): void {
    $keys = array_map(static fn ($gateway) => $gateway->key, PaymentManager::gateways());
    foreach (['cod', 'bank_transfer', 'seylan', 'payhere', 'koko', 'payzy', 'mintpay'] as $key) {
        T::true(in_array($key, $keys, true), "gateway {$key} is not registered (got: " . implode(', ', $keys) . ')');
    }
});

/* --------------------------------------------------------------- catalogue */

T::case('catalog: filter parsing ignores junk and clamps numbers', function (): void {
    $filters = Catalog::parseFilters([
        'q' => '  laptop ',
        'min' => '1000',
        'max' => 'abc',
        'page' => '-3',
        'per' => '500',
        'sort' => 'nonsense',
    ]);
    T::same('laptop', $filters['q']);
    T::same(1000.0, $filters['min']);
    T::same('newest', $filters['sort'], 'unknown sort falls back to newest');
    T::same(1, $filters['page'], 'page must clamp to 1');
    T::same([], $filters['brand']);
    T::same('', $filters['condition'], 'unknown condition is dropped');
});

T::case('catalog: price bounds are swapped when reversed', function (): void {
    $filters = Catalog::parseFilters(['min' => '9000', 'max' => '1500']);
    T::same(1500.0, $filters['min']);
    T::same(9000.0, $filters['max']);
    $negative = Catalog::parseFilters(['min' => '-50']);
    T::same(0.0, (float) $negative['min']);
});

T::case('catalog: rating and flags are clamped', function (): void {
    $filters = Catalog::parseFilters(['rating' => '99', 'in_stock' => '1', 'on_sale' => 'on', 'condition' => 'used']);
    T::same(5, $filters['rating']);
    T::true($filters['in_stock']);
    T::true($filters['on_sale']);
    T::same('used', $filters['condition']);
    T::same(1, Catalog::parseFilters(['rating' => '0'])['rating']);
});

/* ----------------------------------------------------------- maintenance */

T::case('maintenance: only the login POST may reach the shared auth action', function (): void {
    T::true(smm_maintenance_login_allowed('/actions/auth.php', 'POST', 'login'));
    T::true(smm_maintenance_login_allowed('/actions/auth.php?source=store', 'post', 'login'));
    T::false(smm_maintenance_login_allowed('/actions/auth.php', 'POST', 'register'));
    T::false(smm_maintenance_login_allowed('/actions/auth.php', 'POST', 'forgot'));
    T::false(smm_maintenance_login_allowed('/actions/account.php', 'POST', 'login'));
    T::false(smm_maintenance_login_allowed('/actions/auth.php', 'GET', 'login'));
});

/* -------------------------------------------------------------- return cases */

T::case('returns: lifecycle only allows valid next steps', function (): void {
    T::same(['APPROVED', 'REJECTED', 'CANCELLED'], ReturnService::TRANSITIONS['REQUESTED']);
    T::same(['IN_TRANSIT', 'RECEIVED', 'CANCELLED'], ReturnService::TRANSITIONS['APPROVED']);
    T::same(['REFUNDED', 'EXCHANGED', 'REJECTED', 'CLOSED'], ReturnService::TRANSITIONS['INSPECTING']);
    T::same([], ReturnService::TRANSITIONS['REFUNDED']);
    T::true(isset(ReturnService::REASONS['DAMAGED'], ReturnService::REASONS['WRONG_ITEM']));
    T::same(95.0, ReturnService::calculateRefundCap(100, 200, 20, 10, 250, 0));
    T::same(50.0, ReturnService::calculateRefundCap(100, 200, 20, 10, 250, 200));
    T::same(0.0, ReturnService::calculateRefundCap(100, 200, 20, 10, 250, 250));
});

/* ---------------------------------------------------------------- sql files */

T::case('sql: one additive schema file contains the complete database structure', function (): void {
    $root = dirname(__DIR__);
    $schema = (string) file_get_contents($root . '/database/schema.sql');

    preg_match_all('/CREATE TABLE IF NOT EXISTS `(\w+)`/', $schema, $matches);
    $tables = $matches[1];
    T::same(27, count($tables), 'expected exactly 27 tables in schema.sql');
    T::same(count($tables), count(array_unique($tables)), 'schema table names must be unique');
    foreach (['return_requests', 'return_request_items', 'return_events', 'email_verifications'] as $table) {
        T::contains('CREATE TABLE IF NOT EXISTS `' . $table . '`', $schema, "schema.sql is missing required table {$table}");
    }
    T::false(str_contains($schema, 'DROP TABLE'), 'schema.sql must preserve existing tables');
    T::contains('must_change_password', $schema, 'schema needs the forced first-login password flag');
    T::contains('PREPARE ym_password_change_stmt', $schema, 'schema needs a re-import-safe password flag upgrade');
    T::contains('INSERT IGNORE INTO `settings`', $schema, 'schema should add only missing default settings');
    T::contains("`setting_value` = 'SoftMyMarket'", $schema, 'schema should normalize exact old brand defaults');
    T::false(is_file($root . '/database/install.sql'), 'schema.sql must be the only SQL file');
});

T::case('registration grants admin once and customer thereafter', function (): void {
    $registration = (string) file_get_contents(dirname(__DIR__) . '/actions/auth.php');
    T::contains('GET_LOCK(:lock_name, 10)', $registration, 'first registration role assignment must be serialized');
    T::contains("'role' => \$isFirstAccount ? 'admin' : 'customer'", $registration, 'only first registrant becomes admin');
    T::contains("'status' => \$isFirstAccount ? 'active' : 'pending'", $registration, 'first admin is active, later customers verify email');
});

T::case('team invitations email a temporary password and force a first-login change', function (): void {
    $teamActions = (string) file_get_contents(dirname(__DIR__) . '/admin/actions.php');
    $mailer = (string) file_get_contents(dirname(__DIR__) . '/includes/Mailer.php');
    $bootstrap = (string) file_get_contents(dirname(__DIR__) . '/includes/bootstrap.php');
    $accountAction = (string) file_get_contents(dirname(__DIR__) . '/actions/account.php');
    T::contains('Mailer::teamInvitation', $teamActions, 'creating a team account must send its invitation');
    T::contains('must_change_password', $teamActions, 'new team account must require a password change');
    T::contains('Temporary password:', $mailer, 'invitation email must contain the temporary password');
    T::contains("redirect('/account/settings.php?force_password=1')", $bootstrap, 'forced-password users must be routed to settings');
    T::contains("'must_change_password' => 0", $accountAction, 'changing the password must clear the forced-change flag');
});

T::case('settings: YourMarket defaults and dashboard customization fields are available', function (): void {
    $root = dirname(__DIR__);
    T::same('YourMarket', Settings::DEFAULTS['store_name'], 'brand default');
    foreach (['store_description', 'orders_email', 'store_language', 'store_locality', 'store_country_code', 'social_image_url', 'twitter_url', 'meta_pixel_id', 'return_window_days', 'delivery_promise', 'faq_items', 'trust_delivery_text'] as $key) {
        T::true(array_key_exists($key, Settings::DEFAULTS), 'missing setting default: ' . $key);
    }
    $adminSettings = (string) file_get_contents($root . '/admin/includes/bootstrap.php');
    foreach (['store_description', 'orders_email', 'social_image_url', 'twitter_url', 'trust_delivery_text', 'return_window_days', 'faq_items'] as $key) {
        T::contains("'" . $key . "' =>", $adminSettings, 'missing Admin setting field ' . $key);
    }
    T::contains("Settings::get('currency_symbol'", (string) file_get_contents($root . '/includes/helpers.php'), 'money formatting should honor the dashboard currency symbol');
    T::contains("Settings::get('meta_pixel_id')", (string) file_get_contents($root . '/includes/layout/footer.php'), 'configured Meta Pixel should be included on storefront pages');

    T::contains("`setting_value` = 'SoftMyMarket'", (string) file_get_contents($root . '/database/schema.sql'), 'schema should update only known old brand defaults');
});

T::case('invoice: configured store logo is used with initials as fallback', function (): void {
    $invoice = (string) file_get_contents(dirname(__DIR__) . '/invoice.php');
    T::contains("if (!empty(\$brand['logo']))", $invoice, 'invoice should render the configured brand logo');
    T::contains("img_url((string) \$brand['logo']", $invoice, 'invoice logo should use the existing external-image proxy');
    T::contains("e(\$brand['initials'])", $invoice, 'invoice should retain initials when no logo is configured');
    T::contains('invoice-brand-name fw-bold', $invoice, 'brand name should appear beneath the logo');
});

T::case('invoice: admin print variants and warranty details are present', function (): void {
    $invoice = (string) file_get_contents(dirname(__DIR__) . '/invoice.php');
    $orders = (string) file_get_contents(dirname(__DIR__) . '/includes/Orders.php');
    $items = (string) file_get_contents(dirname(__DIR__) . '/includes/partials/order-items-table.php');
    T::contains('print=a5', $invoice, 'admin A5 invoice action');
    T::contains('print=label', $invoice, 'admin package sticker action');
    T::contains('$isShippingLabel', $invoice, 'label view must be separated from the invoice items');
    T::contains('p.warranty AS warranty', $orders, 'product warranty must be available for order items');
    T::contains('Warranty:</strong>', $items, 'invoice items should display warranty details');
    T::contains('Payment received:', $invoice, 'admin A5 invoice includes payment state');
});

T::case('assets: every demo product image exists', function (): void {
    $root = dirname(__DIR__);
    for ($i = 1; $i <= 12; $i++) {
        $path = sprintf('%s/assets/img/demo/product-%02d.svg', $root, $i);
        T::true(is_file($path), 'missing ' . $path);
    }
});

T::case('sql: identifiers are backtick-quoted (MySQL reserved words)', function (): void {
    // `condition` is a reserved word in MySQL/MariaDB: an unquoted
    // "SET condition = ?" is error 1064, which used to break every product save.
    $bt = chr(96);
    T::same($bt . 'condition' . $bt, Database::quote('condition'), 'quote() must backtick the name');
    T::same($bt . 'products' . $bt, Database::quoteTable('products'), 'quoteTable() must backtick the name');
    T::same($bt . 'condition' . $bt, Database::identifier('condition'), 'identifier() is the quoted alias');

    foreach (['condition; users', 'bad name', '`x`', '1abc', 'a-b', ''] as $bad) {
        $rejected = false;
        try {
            Database::quote($bad);
        } catch (InvalidArgumentException) {
            $rejected = true;
        }
        T::true($rejected, 'unsafe identifier accepted: ' . $bad);
    }
});

T::case('sql: no hand-written query uses an unquoted reserved word', function (): void {
    require_once dirname(__DIR__) . '/tools/sql-audit.php';
    $findings = smm_sql_reserved_audit(dirname(__DIR__));
    $messages = array_map(
        static fn (array $f): string => $f['file'] . ':' . $f['line'] . ' → ' . $f['word'] . ' in: ' . $f['sql'],
        $findings
    );
    T::same([], $messages, 'MySQL/MariaDB reserved words must be wrapped in backticks');
});

T::case('home: hero controls sit in a bar, never over the copy', function (): void {
    $index = (string) file_get_contents(dirname(__DIR__) . '/index.php');
    T::contains('hero-controls-inner', $index, 'control bar');
    T::false(str_contains($index, 'carousel-control-prev'), 'full-height side controls overlap the copy on tablets');
    T::false(str_contains($index, 'carousel-control-next'), 'full-height side controls overlap the copy on tablets');
    T::contains('class="hero-stats"', $index, 'stats list');
    T::false(str_contains($index, 'hero-stats d-flex'), 'stats must be a grid so the values cannot collide');
    T::false(str_contains($index, 'position-absolute" style="bottom'), 'indicators get their position from the stylesheet');
});

T::case('home: hero css stays fluid and overlap-free', function (): void {
    $css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/app.css');
    T::contains('.hero-carousel .hero-stats {', $css, 'scoped stats rules');
    T::contains('grid-template-columns: repeat(3, minmax(0, 1fr))', $css, 'stats grid');
    T::contains('.hero-carousel .hero-slide {', $css, 'scoped slide rules');
    T::false(str_contains($css, "\n.hero-slide {"), 'unscoped slide rules leak into the promo tiles');
    T::contains('safe-area-inset-bottom', $css, 'control bar clears the home indicator');
    T::contains('prefers-reduced-motion: reduce', $css, 'reduced motion');
});

T::case('header: logo left, search centre, actions right', function (): void {
    $header = (string) file_get_contents(dirname(__DIR__) . '/includes/layout/header.php');
    $menu = strpos($header, 'data-bs-target="#navDrawer"');
    $logo = strpos($header, '<a class="logo"');
    $search = strpos($header, '<form class="search-shell"');
    $actions = strpos($header, '<div class="header-actions">');
    T::true($menu !== false && $logo !== false && $search !== false && $actions !== false, 'header blocks missing');
    T::true($menu < $logo && $logo < $search && $search < $actions, 'header order must be menu, logo, search, actions');

    $group = substr($header, $actions);
    T::contains('/account/wishlist.php', $group, 'wishlist belongs to the right-hand action group');
    T::contains('data-open-cart', $group, 'cart belongs to the right-hand action group');
    T::contains('bi bi-person', $group, 'sign in belongs to the right-hand action group');
    T::contains('data-wishlist-count', $group, 'wishlist badge');
});

T::case('header: css keeps the search flexible and centred', function (): void {
    $css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/app.css');
    T::contains('flex: 1 1 auto;', $css, 'the search must grow into the middle');
    T::contains('max-width: 46rem;', $css, 'the search must stay scannable');
    T::contains('margin-inline: auto;', $css, 'the search must centre once capped');
    T::contains('flex: 0 0 auto;', $css, 'the action group must not shrink');
    T::contains('.header-main { flex-wrap: wrap; row-gap: .4rem; }', $css, 'small screens stack the search');
});

T::case('home: brand marquee sits between new arrivals and the help card', function (): void {
    $index = (string) file_get_contents(dirname(__DIR__) . '/index.php');
    $newArrivals = strpos($index, 'New arrivals');
    $marquee = strpos($index, 'brand-marquee');
    $help = strpos($index, 'Need help choosing?');
    T::true($newArrivals !== false && $marquee !== false && $help !== false, 'a home section is missing');
    T::true($newArrivals < $marquee && $marquee < $help, 'the brand rail must sit between new arrivals and help');

    T::contains('$brandStrip !== []', $index, 'the rail only renders when brands exist');
    T::contains('brand-marquee-group', $index, 'the rail needs its looping copies');
    T::contains('aria-hidden="true"', $index, 'the duplicate copy must be hidden from assistive tech');
    T::contains('tabindex="-1"', $index, 'the duplicate copy must stay out of the tab order');
    T::contains('brand%5B%5D=', $index, 'each chip links to the brand filter');
});

T::case('home: the covered-every-order block cannot spill over the next section', function (): void {
    $css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/app.css');
    T::contains(".review-card-empty {\n  height: auto;", $css, 'the empty state must size to its content');
    T::contains('.row > [class*="col"] > .review-card', $css, 'only grid children may stretch to the row height');
    T::contains('.card-soft.h-100 { height: auto; min-height: 100%; }', $css, 'cards must grow instead of overflowing');
});

T::case('home: support dock collapses into one launcher', function (): void {
    $footer = (string) file_get_contents(dirname(__DIR__) . '/includes/layout/footer.php');
    $css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/app.css');
    T::contains('data-dock-toggle', $footer, 'the dock needs a launcher');
    T::contains('aria-expanded="false"', $footer, 'the launcher must report its state');
    T::contains('support-channels', $footer, 'the channels live in their own panel');
    T::contains('tabindex="-1"', $footer, 'closed channels stay out of the tab order');
    T::contains('.support-widget.is-open .support-channels { display: flex; }', $css, 'the panel opens on tap');
});

T::case('products: shop and category pages render up to 60 products per page', function (): void {
    $shop = (string) file_get_contents(dirname(__DIR__) . '/shop.php');
    $category = (string) file_get_contents(dirname(__DIR__) . '/category.php');
    T::contains('$filters[\'per\'] = 60;', $shop, 'shop page size');
    T::contains('$filters[\'per\'] = 60;', $category, 'category page size');
    T::contains('min(60,', (string) file_get_contents(dirname(__DIR__) . '/includes/Catalog.php'), 'page-size upper bound');
});

T::case('products: admin form selects a parent before its subcategory', function (): void {
    $form = (string) file_get_contents(dirname(__DIR__) . '/admin/product-form.php');
    $parent = strpos($form, 'id="parent_category_id" name="parent_category_id"');
    $child = strpos($form, 'id="category_id" name="category_id"');
    T::true($parent !== false && $child !== false && $parent < $child, 'parent selector must come first');
    T::contains('data-parent-group=', $form, 'subcategories must be grouped beneath their parent');
    T::contains('parent_category_id', (string) file_get_contents(dirname(__DIR__) . '/admin/actions.php'), 'save parent relation');
    T::contains('Choose a subcategory belonging to the selected parent category.', (string) file_get_contents(dirname(__DIR__) . '/includes/Catalog.php'), 'reject mismatched parent-child posts');
});

T::case('categories: storefront count reads the total count field', function (): void {
    $catalog = (string) file_get_contents(dirname(__DIR__) . '/includes/Catalog.php');
    $view = (string) file_get_contents(dirname(__DIR__) . '/includes/View.php');
    T::contains('$category[\'product_count\'] = (int) ($counts[(int) $category[\'id\']][\'total\'] ?? 0);', $catalog, 'featured category count');
    T::contains('$category[\'product_count\'] = (int) ($counts[(int) $category[\'id\']][\'total\'] ?? 0);', $view, 'view helper count');
});

T::case('categories: subtree query walks parent_id instead of trusting materialised paths', function (): void {
    $catalog = (string) file_get_contents(dirname(__DIR__) . '/includes/Catalog.php');
    T::contains('foreach (self::categories(false) as $row)', $catalog, 'load category relationships');
    T::contains('$children[$parent][] = (int) $row[\'id\'];', $catalog, 'build child adjacency list');
    T::contains('while ($queue !== [])', $catalog, 'walk all descendants');
    T::false(str_contains($catalog, 'path LIKE :pathLike'), 'stale materialised paths must not hide child products');
});

T::case('products: one image-links field previews links beneath it', function (): void {
    $form = (string) file_get_contents(dirname(__DIR__) . '/admin/product-form.php');
    $textarea = strpos($form, 'name="image_links"');
    $preview = strpos($form, 'data-image-preview-grid');
    T::true($textarea !== false && $preview !== false && $textarea < $preview, 'preview must follow the single image-links field');
    T::contains('data-image-links', $form, 'image field hook');
    T::false(str_contains($form, 'name="gallery[]"'), 'remove per-image link inputs');
    T::false(str_contains($form, 'name="image_url"'), 'remove the separate primary-image input');
    T::false(str_contains($form, 'data-image-paste-box'), 'remove the separate paste textarea');
});

T::case('products: image links support 20 images and preserve only entered links', function (): void {
    $form = (string) file_get_contents(dirname(__DIR__) . '/admin/product-form.php');
    $actions = (string) file_get_contents(dirname(__DIR__) . '/admin/actions.php');
    $catalog = (string) file_get_contents(dirname(__DIR__) . '/includes/Catalog.php');
    $product = (string) file_get_contents(dirname(__DIR__) . '/product.php');
    T::contains('0 / 20', $form, '20-image counter');
    T::contains('up to <strong>20 image links</strong>', $form, '20-link guidance');
    T::contains('count($imageUrls) >= 20', $actions, 'server-side 20-image cap');
    T::contains('$row[\'images\'] = array_slice($images, 0, 20);', $catalog, '20 images from product data');
    T::contains('array_slice($images, 0, 20)', $product, 'display all saved gallery thumbnails');
    T::contains('array_slice($imageUrls, 1, 19)', $actions, 'first image is primary, up to 19 are gallery items');
});

T::case('branding: admin sidebar uses logo without repeating the brand name', function (): void {
    $layout = (string) file_get_contents(dirname(__DIR__) . '/admin/includes/layout-top.php');
    $start = strpos($layout, '<a class="brand');
    $end = strpos($layout, '</a>', $start === false ? 0 : $start);
    T::true($start !== false && $end !== false, 'admin sidebar brand link missing');
    $brand = substr($layout, $start, $end - $start);
    T::contains('!empty($adminBrand', $brand, 'conditional branding logo');
    T::contains('admin-brand-logo', $brand, 'logo should display when configured');
    T::contains('<small>Dashboard</small>', $brand, 'Dashboard label stays under logo');
    T::contains('adminBrand[', $brand, 'brand name is the no-logo fallback');
    T::false(str_contains($brand, '<span><strong>'), 'do not show the brand name beside the logo');
});

T::case('branding: storefront footer shows either logo or brand text, not both', function (): void {
    $footer = (string) file_get_contents(dirname(__DIR__) . '/includes/layout/footer.php');
    $start = strpos($footer, '<a class="logo footer-branding');
    $end = strpos($footer, '</a>', $start === false ? 0 : $start);
    T::true($start !== false && $end !== false, 'footer brand link missing');
    $brand = substr($footer, $start, $end - $start);
    T::contains('!empty($brand', $brand, 'conditional storefront logo');
    T::contains('alt=', $brand, 'logo remains accessible by brand name');
    T::contains('footer-brand-name', $brand, 'brand name is shown without a logo');
    T::false(str_contains($brand, 'class="logo-text"'), 'no duplicate brand wordmark beside logo');
});

/* --------------------------------------------------------------------- db */

if ($withDb) {
    echo "\nDatabase checks\n";

    T::case('db: schema has every expected table', function (): void {
        $expected = ['users', 'user_addresses', 'categories', 'brands', 'products', 'reviews', 'cart_items',
            'wishlist_items', 'orders', 'order_items', 'order_events', 'payments', 'coupons',
            'coupon_redemptions', 'inventory_movements', 'notifications', 'settings', 'rate_limits',
            'audit_log', 'newsletter_subscribers', 'contact_messages', 'search_log', 'password_resets'];
        $tables = array_map(static fn ($row) => (string) $row['t'], Database::all('SHOW TABLES AS t'));
        foreach ($expected as $table) {
            $full = Database::table($table);
            $found = in_array($full, $tables, true) || in_array($table, $tables, true);
            T::true($found, "missing table: {$full} (have: " . implode(', ', $tables) . ')');
        }
    });

    T::case('db: category tree is consistent', function (): void {
        $orphans = Database::int(
            'SELECT COUNT(*) FROM ' . Database::table('categories') . ' c
             LEFT JOIN ' . Database::table('categories') . ' p ON p.id = c.parent_id
             WHERE c.parent_id IS NOT NULL AND p.id IS NULL'
        );
        T::same(0, $orphans, 'orphaned categories');
        $badDepth = Database::int(
            'SELECT COUNT(*) FROM ' . Database::table('categories') . ' c
             JOIN ' . Database::table('categories') . ' p ON p.id = c.parent_id
             WHERE c.depth <> p.depth + 1'
        );
        T::same(0, $badDepth, 'categories with an inconsistent depth');
    });

    T::case('db: products reference real categories and brands', function (): void {
        $badCategory = Database::int(
            'SELECT COUNT(*) FROM ' . Database::table('products') . ' p
             LEFT JOIN ' . Database::table('categories') . ' c ON c.id = p.category_id
             WHERE c.id IS NULL'
        );
        T::same(0, $badCategory, 'products with a missing category');
        $badBrand = Database::int(
            'SELECT COUNT(*) FROM ' . Database::table('products') . ' p
             LEFT JOIN ' . Database::table('brands') . ' b ON b.id = p.brand_id
             WHERE p.brand_id IS NOT NULL AND b.id IS NULL'
        );
        T::same(0, $badBrand, 'products with a missing brand');
        $badPrice = Database::int('SELECT COUNT(*) FROM ' . Database::table('products') . ' WHERE price < 0');
        T::same(0, $badPrice, 'products with a negative price');
    });

    T::case('db: every user has a hashed password', function (): void {
        $rows = Database::all('SELECT email, password_hash FROM ' . Database::table('users') . ' LIMIT 500');
        T::true($rows !== [], 'no users found — run the installer first');
        foreach ($rows as $row) {
            $info = password_get_info((string) $row['password_hash']);
            T::true(($info['algo'] ?? 0) !== 0, 'plaintext password for ' . $row['email']);
        }
    });

    T::case('db: order totals match their items', function (): void {
        $rows = Database::all(
            'SELECT o.id, o.order_number, o.subtotal, COALESCE(SUM(i.total), 0) AS items_total
             FROM ' . Database::table('orders') . ' o
             LEFT JOIN ' . Database::table('order_items') . ' i ON i.order_id = o.id
             GROUP BY o.id, o.order_number, o.subtotal
             HAVING ABS(o.subtotal - items_total) > 0.01
             LIMIT 10'
        );
        T::same([], $rows, 'orders whose subtotal does not match their line items');
    });

    T::case('db: every order has a timeline event', function (): void {
        $missing = Database::int(
            'SELECT COUNT(*) FROM ' . Database::table('orders') . ' o
             WHERE NOT EXISTS (SELECT 1 FROM ' . Database::table('order_events') . ' e WHERE e.order_id = o.id)'
        );
        T::same(0, $missing, 'orders without any order_events row');
    });

    T::case('db: pricing quote is arithmetically correct', function (): void {
        $cart = [
            'subtotal' => 20000.0,
            'items' => [
                ['quantity' => 2, 'free_shipping' => false, 'product' => ['weight_grams' => 500]],
            ],
        ];
        $quote = Pricing::quote($cart, null, 'COD');
        T::true(abs($quote['total'] - ($quote['subtotal'] + $quote['shipping'] + $quote['tax'] - $quote['discount'])) < 0.01, 'total must equal the breakdown');
        T::true($quote['subtotal'] >= 0 && $quote['total'] >= 0);
    });

    T::case('db: reports return well-formed rows', function (): void {
        $summary = Reports::summary();
        T::true(is_array($summary) && $summary !== [], 'reports summary is empty');
    });
}

exit(T::summary());

<?php
/**
 * SoftMyMarket data seeder.
 *
 * CLI (recommended for a full-size catalogue):
 *   php tools/seed.php --products=10000 --customers=10000 --orders=250
 *   php tools/seed.php --demo            # small demo set for the installer
 *   php tools/seed.php --products=0 --customers=0 --demo --force
 *
 * The installer includes this file and calls seed_demo_data() directly.
 *
 * Everything is written inside one transaction per phase, in batches, so a
 * 20,000 row seed finishes in well under a minute on shared hosting.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('SMM_SEED_ALLOW_WEB')) {
    // Only reachable from install/index.php (which defines the constant) or CLI.
    http_response_code(403);
    exit('This script is meant to be run from the command line.');
}

if (!function_exists('uuid')) {
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
}

mt_srand(20261024);

/** Table name with the optional configured prefix. */
function seed_table(string $name): string
{
    return Database::table($name);
}

/** Random pick. */
function seed_pick(array $items): mixed
{
    return $items[mt_rand(0, count($items) - 1)];
}

/** Bulk insert helper: $rows is a list of identical-shape associative arrays. */
function seed_bulk(string $table, array $rows, int $chunk = 400): int
{
    if ($rows === []) {
        return 0;
    }
    $pdo = Database::pdo();
    $columns = array_keys($rows[0]);
    $columnSql = '`' . implode('`,`', $columns) . '`';
    $placeholder = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
    $inserted = 0;

    foreach (array_chunk($rows, $chunk) as $batch) {
        $values = [];
        foreach ($batch as $row) {
            foreach ($columns as $column) {
                $values[] = $row[$column] ?? null;
            }
        }
        $sql = 'INSERT INTO `' . seed_table($table) . '` (' . $columnSql . ') VALUES '
            . implode(',', array_fill(0, count($batch), $placeholder));
        $statement = $pdo->prepare($sql);
        $statement->execute($values);
        $inserted += count($batch);
    }
    return $inserted;
}

/* ------------------------------------------------------------------ taxonomy */

/** Departments → sub-categories → (optionally) a third level. */
function seed_taxonomy(): array
{
    return [
        ['Mobiles & Tablets', 'bi-phone', ['Smartphones' => ['Android Phones', 'iPhones', 'Budget Phones'], 'Tablets' => ['Android Tablets', 'iPads'], 'Accessories' => ['Cases & Covers', 'Chargers', 'Power Banks']]],
        ['Electronics', 'bi-headphones', ['Audio' => ['Headphones', 'Speakers', 'Home Theatre'], 'TV & Video' => ['Smart TVs', 'Projectors'], 'Cameras' => ['DSLR', 'Action Cameras']]],
        ['Computers & Gaming', 'bi-laptop', ['Laptops' => ['Business Laptops', 'Gaming Laptops'], 'Desktops' => ['All-in-One', 'Mini PCs'], 'Gaming' => ['Consoles', 'Controllers', 'Gaming Chairs'], 'Printers & Ink']],
        ['Home & Kitchen', 'bi-house-door', ['Kitchen' => ['Cookware', 'Blenders', 'Rice Cookers'], 'Furniture' => ['Chairs', 'Storage'], 'Bedding' => ['Pillows', 'Mattresses'], 'Home Care' => ['Fans', 'Irons']]],
        ['Fashion', 'bi-bag', ['Women' => ['Dresses', 'Handbags', 'Footwear'], 'Men' => ['Shirts', 'Trousers', 'Sneakers'], 'Kids' => ['School Wear', 'Toys']]],
        ['Health & Beauty', 'bi-heart-pulse', ['Skincare' => ['Cleansers', 'Sunscreen'], 'Hair Care' => ['Shampoo', 'Treatments'], 'Wellness' => ['Vitamins', 'Fitness']]],
        ['Grocery & Essentials', 'bi-basket', ['Pantry' => ['Rice & Grains', 'Spices', 'Tea & Coffee'], 'Beverages' => ['Juices', 'Soft Drinks'], 'Household' => ['Cleaning', 'Paper Goods']]],
        ['Sports & Outdoors', 'bi-bicycle', ['Fitness' => ['Dumbbells', 'Yoga Mats'], 'Outdoor' => ['Camping', 'Cycling'], 'Water Sports' => ['Swimwear', 'Goggles']]],
        ['Baby & Kids', 'bi-balloon', ['Baby Care' => ['Diapers', 'Feeding'], 'Kids Clothing' => ['Boys', 'Girls'], 'Learning' => ['Books', 'Puzzles']]],
        ['Automotive', 'bi-car-front', ['Car Care' => ['Polish', 'Vacuum'], 'Car Audio' => ['Speakers', 'Head Units'], 'Motorbike' => ['Helmets', 'Lubricants']]],
    ];
}

function seed_brands(): array
{
    return [
        ['Samsung', '#1428a0'], ['Apple', '#333333'], ['Xiaomi', '#ff6900'], ['Sony', '#000000'],
        ['LG', '#a50034'], ['Panasonic', '#0033a0'], ['Philips', '#0b5ed7'], ['JBL', '#eb4d23'],
        ['Anker', '#00a4e4'], ['Baseus', '#1f6feb'], ['HP', '#0096d6'], ['Dell', '#007db8'],
        ['Lenovo', '#e2231a'], ['Asus', '#00539b'], ['Canon', '#cc0000'], ['Nikon', '#ffd500'],
        ['Nike', '#111111'], ['Adidas', '#1d1d1d'], ['Puma', '#c8102e'], ['Zara', '#000000'],
        ['Dilmah', '#0f7d3d'], ['Nestlé', '#c8102e'], ['Unilever', '#1b5eab'], ['Abans', '#e63329'],
        ['Damro', '#7a3f13'], ['Anchor', '#004a99'], ['Ceylon Tea', '#116b3c'], ['Wildcraft', '#3b7a2f'],
    ];
}

function seed_coupons(): array
{
    return [
        ['WELCOME10', 'percent', 10, 0, 5000, '10% off your first order', 2000],
        ['SOFTDEAL15', 'percent', 15, 5000, 7500, '15% off orders over Rs 5,000', 1000],
        ['FREESHIP', 'free_shipping', 0, 3000, 900, 'Free delivery on orders over Rs 3,000', 5000],
        ['FLASH5K', 'fixed', 5000, 40000, 5000, 'Rs 5,000 off orders over Rs 40,000', 200],
        ['AVURUDU20', 'percent', 20, 10000, 10000, 'Avurudu festival offer', 1],
    ];
}

/* ------------------------------------------------------------------- people */

function seed_name_parts(): array
{
    $first = ['Kasun', 'Nimal', 'Sanduni', 'Dilini', 'Tharindu', 'Ishara', 'Ruwan', 'Chathuri', 'Amali', 'Nuwan',
        'Mohamed', 'Ayesha', 'Rizwan', 'Fathima', 'Arjun', 'Kavitha', 'Dinesh', 'Shalini', 'Vikram', 'Meena',
        'Sahan', 'Hasini', 'Lahiru', 'Piumi', 'Janith', 'Nadeesha', 'Chamara', 'Dinithi', 'Ashan', 'Rashmi',
        'Thilini', 'Sameera', 'Praveen', 'Anjali', 'Bimal', 'Sachini', 'Gayan', 'Nirosha', 'Udaya', 'Malsha'];
    $last = ['Perera', 'Fernando', 'Silva', 'Jayasinghe', 'Bandara', 'Wickramasinghe', 'Rathnayake', 'Gunawardena',
        'Herath', 'Ekanayake', 'Rajapaksha', 'Dissanayake', 'Senanayake', 'Wijesinghe', 'Karunaratne',
        'Mohamed', 'Hassan', 'Ibrahim', 'Rahman', 'Nazeer', 'Kumar', 'Pillai', 'Nair', 'Menon', 'Rao'];
    return [$first, $last];
}

function seed_cities(): array
{
    return [
        ['Colombo 03', 'Colombo', '00300'], ['Colombo 07', 'Colombo', '00700'], ['Nugegoda', 'Colombo', '10250'],
        ['Dehiwala', 'Colombo', '10350'], ['Rajagiriya', 'Colombo', '10100'], ['Negombo', 'Gampaha', '11500'],
        ['Gampaha', 'Gampaha', '11000'], ['Kalutara', 'Kalutara', '12000'], ['Galle', 'Galle', '80000'],
        ['Matara', 'Matara', '81000'], ['Kandy', 'Kandy', '20000'], ['Peradeniya', 'Kandy', '20400'],
        ['Kurunegala', 'Kurunegala', '60000'], ['Anuradhapura', 'Anuradhapura', '50000'],
        ['Jaffna', 'Jaffna', '40000'], ['Batticaloa', 'Batticaloa', '30000'], ['Ratnapura', 'Ratnapura', '70000'],
        ['Badulla', 'Badulla', '90000'], ['Nuwara Eliya', 'Nuwara Eliya', '22200'], ['Hambantota', 'Hambantota', '82000'],
    ];
}

function seed_street(): array
{
    return ['Galle Road', 'Kandy Road', 'Temple Road', 'Lake Drive', 'Station Road', 'Main Street',
        'Hospital Road', 'Hill Street', 'Beach Road', 'Park Avenue', 'Market Lane', 'School Lane'];
}

/** One address array in the shape orders and user_addresses expect. */
function seed_address(string $fullName, string $phone): array
{
    [$city, $district, $postal] = seed_pick(seed_cities());
    return [
        'full_name' => $fullName,
        'phone' => $phone,
        'line1' => mt_rand(1, 250) . ', ' . seed_pick(seed_street()),
        'line2' => mt_rand(1, 3) === 1 ? 'Lane ' . mt_rand(1, 9) : '',
        'city' => $city,
        'district' => $district,
        'postal_code' => $postal,
        'country' => 'Sri Lanka',
    ];
}

/** 07XXXXXXXX mobile number. */
function seed_phone(): string
{
    $prefix = seed_pick(['70', '71', '75', '76', '77', '78']);
    return '07' . substr($prefix, 1) . str_pad((string) mt_rand(0, 9999999), 7, '0', STR_PAD_LEFT);
}

/* ----------------------------------------------------------------- products */

function seed_product_words(): array
{
    return [
        'Smartphones' => [
            ['prefix' => ['Galaxy', 'Redmi', 'iPhone', 'Nokia', 'Poco'], 'noun' => ['5G Phone', 'Smartphone', 'Lite Phone']],
            'price' => [28000, 420000], 'specs' => ['Display' => 'AMOLED', 'Battery' => '5000 mAh', 'Warranty' => '1 year'],
        ],
        'Laptops' => [
            ['prefix' => ['IdeaPad', 'MacBook', 'Pavilion', 'VivoBook', 'ThinkPad'], 'noun' => ['Laptop', 'Ultrabook', 'Notebook']],
            'price' => [95000, 850000], 'specs' => ['Processor' => 'Intel Core i5', 'RAM' => '16 GB', 'Storage' => '512 GB SSD'],
        ],
        'Smart TVs' => [
            ['prefix' => ['Bravia', 'QLED', 'NanoCell', 'Smart'], 'noun' => ['4K Smart TV', 'LED TV', 'Google TV']],
            'price' => [65000, 550000], 'specs' => ['Resolution' => '4K UHD', 'Size' => '55 inch', 'Smart OS' => 'Android TV'],
        ],
        'Headphones' => [
            ['prefix' => ['QuietComfort', 'Noise', 'Studio', 'Air'], 'noun' => ['Wireless Headphones', 'ANC Headset', 'Earbuds']],
            'price' => [4500, 95000], 'specs' => ['Type' => 'Over-ear', 'Battery' => '30 hours', 'ANC' => 'Yes'],
        ],
        'Cookware' => [
            ['prefix' => ['Non-Stick', 'Stainless', 'Cast Iron', 'Ceramic'], 'noun' => ['Cookware Set', 'Frying Pan', 'Sauce Pan']],
            'price' => [1800, 25000], 'specs' => ['Material' => 'Aluminium', 'Pieces' => '5', 'Induction' => 'Compatible'],
        ],
        'Sneakers' => [
            ['prefix' => ['Air', 'Ultra', 'Court', 'Trail'], 'noun' => ['Sneakers', 'Running Shoes', 'Trainers']],
            'price' => [3500, 42000], 'specs' => ['Upper' => 'Mesh', 'Sole' => 'Rubber', 'Size' => 'UK 9'],
        ],
        'Handbags' => [
            ['prefix' => ['Classic', 'Everyday', 'Mini', 'Leather'], 'noun' => ['Handbag', 'Tote Bag', 'Shoulder Bag']],
            'price' => [2500, 38000], 'specs' => ['Material' => 'Faux leather', 'Closure' => 'Zip', 'Strap' => 'Adjustable'],
        ],
        'Skincare' => [
            ['prefix' => ['Hydra', 'Glow', 'Pure', 'Daily'], 'noun' => ['Face Wash', 'Moisturiser', 'Sunscreen SPF50']],
            'price' => [950, 12500], 'specs' => ['Volume' => '150 ml', 'Skin type' => 'All', 'Made in' => 'Sri Lanka'],
        ],
        'Tea & Coffee' => [
            ['prefix' => ['Ceylon', 'Highland', 'Estate', 'Premium'], 'noun' => ['Black Tea', 'Green Tea', 'Roast Coffee']],
            'price' => [450, 5200], 'specs' => ['Weight' => '400 g', 'Origin' => 'Sri Lanka', 'Packaging' => 'Foil pouch'],
        ],
        'Diapers' => [
            ['prefix' => ['Soft', 'Ultra', 'Comfort', 'Newborn'], 'noun' => ['Diapers Pack', 'Nappy Pants', 'Baby Wipes Combo']],
            'price' => [890, 7800], 'specs' => ['Size' => 'M', 'Count' => '48', 'Type' => 'Tape'],
        ],
        'Camping' => [
            ['prefix' => ['Trail', 'Summit', 'Base', 'All-Weather'], 'noun' => ['Camping Tent', 'Sleeping Bag', 'Backpack']],
            'price' => [3500, 65000], 'specs' => ['Capacity' => '4 persons', 'Weight' => '4.2 kg', 'Waterproof' => 'Yes'],
        ],
        'Car Care' => [
            ['prefix' => ['Shine', 'Gloss', 'Pro', 'Alloy'], 'noun' => ['Car Polish', 'Wax Kit', 'Interior Cleaner']],
            'price' => [750, 9500], 'specs' => ['Volume' => '500 ml', 'Finish' => 'Gloss', 'Use' => 'Exterior'],
        ],
    ];
}

function seed_defaults_for_category(string $name): array
{
    // Generic fallback that still reads like a real product.
    $fallback = [
        'prefix' => [seed_pick(['Essential', 'Premium', 'Everyday', 'Classic'])],
        'noun'   => [$name . ' Item'],
        'price'  => [1200, 45000],
        'specs'  => ['Brand' => 'Assorted', 'Warranty' => '6 months', 'Origin' => 'Imported'],
    ];
    foreach (seed_product_words() as $key => $data) {
        if (stripos($name, $key) === false && stripos($key, $name) === false) {
            continue;
        }
        // Each entry keeps its word lists in slot 0 next to price/specs, so
        // flatten the two shapes into the one callers expect.
        $entry = isset($data[0]) && is_array($data[0]) ? array_merge($data, $data[0]) : $data;
        unset($entry[0]);
        if (empty($entry['prefix']) || empty($entry['noun'])) {
            continue;
        }
        return $entry + $fallback;
    }
    return $fallback;
}

/* ---------------------------------------------------------------- seeding */

/**
 * Small demo data set used by the installer.
 *
 * @return array{products:int,customers:int,categories:int,brands:int}
 */
function seed_demo_data(): array
{
    $categories = seed_catalog_tree();
    $brands = seed_catalog_brands();
    seed_catalog_coupons();
    $productCount = seed_products(120, $categories, $brands);
    $customerCount = seed_customers(40);
    if ($customerCount > 0) {
        seed_orders(25);
    }
    return [
        'products' => $productCount,
        'customers' => $customerCount,
        'categories' => count($categories),
        'brands' => count($brands),
    ];
}

/** Create the category tree and return leaf ids. */
function seed_catalog_tree(): array
{
    $existing = Database::int('SELECT COUNT(*) FROM ' . seed_table('categories'));
    if ($existing > 0) {
        return array_map(static fn ($row) => (int) $row['id'], Database::all(
            "SELECT id FROM " . seed_table('categories') . " WHERE is_active = 1"
        ));
    }
    $leaves = [];
    $position = 1;
    foreach (seed_taxonomy() as [$department, $icon, $children]) {
        $parentId = Catalog::createCategory([
            'name' => $department,
            'icon' => $icon,
            'position' => $position++,
            'is_featured' => true,
            'is_active' => true,
            'color' => seed_pick(['#0c800f', '#0a57a0', '#e11d48', '#b45309']),
        ]);
        $leaves[] = $parentId;
        $childPosition = 1;
        foreach ($children as $childName => $grandChildren) {
            $childId = Catalog::createCategory([
                'name' => $childName,
                'parent_id' => $parentId,
                'position' => $childPosition++,
                'is_active' => true,
            ]);
            $leaves[] = $childId;
            if (is_array($grandChildren)) {
                $grandPosition = 1;
                foreach ($grandChildren as $grandName) {
                    $leaves[] = Catalog::createCategory([
                        'name' => $grandName,
                        'parent_id' => $childId,
                        'position' => $grandPosition++,
                        'is_active' => true,
                    ]);
                }
            }
        }
    }
    return $leaves;
}

/** Create brands and return [id => name]. */
function seed_catalog_brands(): array
{
    $existing = Database::all('SELECT id, name FROM ' . seed_table('brands'));
    if ($existing !== []) {
        return array_column($existing, 'name', 'id');
    }
    $brands = [];
    foreach (seed_brands() as [$name]) {
        $id = Catalog::createBrand(['name' => $name]);
        $brands[(int) $id] = $name;
    }
    return $brands;
}

function seed_catalog_coupons(): void
{
    if (Database::int('SELECT COUNT(*) FROM ' . seed_table('coupons')) > 0) {
        return;
    }
    foreach (seed_coupons() as [$code, $type, $value, $minSpend, $maxDiscount, $description, $maxUses]) {
        Coupons::save([
            'code' => $code,
            'type' => $type,
            'value' => $value,
            'min_spend' => $minSpend,
            'max_discount' => $maxDiscount,
            'description' => $description,
            'max_uses' => $maxUses,
            'per_user_limit' => 1,
            'is_active' => true,
            'starts_at' => date('Y-m-d H:i:s', strtotime('-7 days')),
            'expires_at' => $code === 'AVURUDU20' ? date('Y-m-d', strtotime('-30 days')) : date('Y-m-d', strtotime('+120 days')),
        ]);
    }
}

/**
 * Insert $count products spread across the given categories.
 *
 * @param list<int> $categoryIds
 * @param array<int,string> $brands
 */
function seed_products(int $count, array $categoryIds, array $brands): int
{
    if ($count <= 0 || $categoryIds === []) {
        return 0;
    }
    $categoryNames = [];
    foreach (Database::all('SELECT id, name FROM ' . seed_table('categories')) as $row) {
        $categoryNames[(int) $row['id']] = (string) $row['name'];
    }
    $brandIds = array_keys($brands);
    // Base SKUs and slugs on the highest id so a re-run never collides with a
    // previously seeded (or deleted) product.
    $startIndex = max(1, Database::int('SELECT COALESCE(MAX(id), 0) FROM ' . seed_table('products')) + 1);
    $now = date('Y-m-d H:i:s');
    $rows = [];

    for ($i = 0; $i < $count; $i++) {
        $index = $startIndex + $i;
        $categoryId = $categoryIds[mt_rand(0, count($categoryIds) - 1)];
        $categoryName = $categoryNames[$categoryId] ?? 'General';
        $words = seed_defaults_for_category($categoryName);
        $brandId = $brandIds !== [] && mt_rand(0, 4) > 0 ? $brandIds[mt_rand(0, count($brandIds) - 1)] : null;
        $brandName = $brandId !== null ? $brands[$brandId] : 'SoftMyMarket';
        $title = $brandName . ' ' . seed_pick($words['prefix']) . ' ' . seed_pick($words['noun']) . ' ' . strtoupper(substr(md5((string) $index), 0, 3));
        $price = mt_rand($words['price'][0], $words['price'][1]);
        // Round to a believable retail price.
        $price = (int) (round($price / 50) * 50) - 10;
        $hasDiscount = mt_rand(1, 100) <= 45;
        $compareAt = $hasDiscount ? (int) (round(($price * (mt_rand(110, 165) / 100)) / 50) * 50) - 10 : null;
        $stock = mt_rand(1, 100) <= 8 ? 0 : mt_rand(3, 180);
        $slug = slugify($title) . '-' . $index;
        $specs = $words['specs'];
        $specs['SKU'] = 'SMM-' . str_pad((string) $index, 6, '0', STR_PAD_LEFT);
        // Cycle through the bundled artwork so every seeded product has an image
        // that works offline; replace with Drive links from the product editor.
        $image = '/assets/img/demo/product-' . str_pad((string) (($index % 12) + 1), 2, '0', STR_PAD_LEFT) . '.svg';
        $gallery = [$image];

        $rows[] = [
            'uuid' => uuid(),
            'title' => substr($title, 0, 200),
            'slug' => $slug,
            'sku' => 'SMM-' . str_pad((string) $index, 6, '0', STR_PAD_LEFT),
            'category_id' => $categoryId,
            'brand_id' => $brandId,
            'short_description' => substr($brandName . ' ' . $categoryName . ' — genuine stock with warranty and island-wide delivery.', 0, 300),
            'description' => "{$title}\n\n" . implode("\n", array_map(
                static fn ($key, $value) => "• {$key}: {$value}",
                array_keys($specs),
                array_values($specs)
            )) . "\n\nDelivered from our Colombo warehouse with a 7-day return window. Cash on delivery available island-wide.",
            'price' => $price,
            'compare_at_price' => $compareAt,
            'cost_price' => (int) round($price * 0.72),
            'stock' => $stock,
            'low_stock_alert' => 5,
            'condition' => mt_rand(1, 100) <= 4 ? 'refurbished' : 'new',
            'status' => mt_rand(1, 100) <= 97 ? 'active' : 'draft',
            'is_featured' => mt_rand(1, 100) <= 6 ? 1 : 0,
            'is_flash_deal' => $hasDiscount && mt_rand(1, 100) <= 35 ? 1 : 0,
            'free_shipping' => $price >= 15000 ? 1 : 0,
            'shipping_fee' => 0,
            'warranty' => seed_pick(['6 months warranty', '1 year local warranty', '2 year warranty', 'No warranty']),
            'weight_grams' => mt_rand(120, 9000),
            'image_url' => $image,
            'gallery' => json_encode($gallery, JSON_UNESCAPED_SLASHES),
            'tags' => strtolower($categoryName . ',' . $brandName . ',' . seed_pick(['sale', 'new', 'popular', 'bestseller'])),
            'specs' => json_encode($specs, JSON_UNESCAPED_UNICODE),
            'rating' => 0,
            'review_count' => 0,
            'sold_count' => mt_rand(0, 400),
            'view_count' => mt_rand(10, 9000),
            'created_at' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(0, 240) . ' days')),
            'updated_at' => $now,
        ];
    }

    $inserted = seed_bulk('products', $rows);
    echo "  products: {$inserted}\n";

    // Opening stock movement so the inventory screen has a history from day one.
    $created = Database::all(
        'SELECT id, stock FROM ' . seed_table('products') . ' WHERE id >= ' . $startIndex
        . ' AND stock > 0 ORDER BY id'
    );
    $movements = [];
    foreach ($created as $product) {
        $movements[] = [
            'product_id' => (int) $product['id'],
            'change_qty' => (int) $product['stock'],
            'reason' => 'opening',
            'reference' => 'SEED',
            'note' => 'Opening stock loaded with the demo catalogue.',
            'admin_id' => null,
            'created_at' => $now,
        ];
    }
    seed_bulk('inventory_movements', $movements);
    return $inserted;
}

/** Insert $count customers (role=customer) with one saved address each. */
function seed_customers(int $count): int
{
    if ($count <= 0) {
        return 0;
    }
    [$firstNames, $lastNames] = seed_name_parts();
    $hash = password_hash('Password123!', PASSWORD_BCRYPT, ['cost' => 12]);
    $now = date('Y-m-d H:i:s');
    $offset = max(1, Database::int('SELECT COALESCE(MAX(id), 0) FROM ' . seed_table('users')) + 1);
    $rows = [];

    for ($i = 0; $i < $count; $i++) {
        $index = $offset + $i;
        $name = seed_pick($firstNames) . ' ' . seed_pick($lastNames);
        $email = strtolower('customer' . $index . '.' . substr(md5('c' . $index), 0, 6) . '@example.lk');
        $phone = seed_phone();
        $rows[] = [
            'uuid' => uuid(),
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'password_hash' => $hash,
            'role' => 'customer',
            'status' => mt_rand(1, 100) <= 98 ? 'active' : 'suspended',
            'marketing_opt_in' => mt_rand(0, 1),
            'email_verified_at' => $now,
            'last_login_at' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(0, 60) . ' days')),
            'created_at' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(1, 500) . ' days')),
            'updated_at' => $now,
        ];
    }

    $inserted = seed_bulk('users', $rows);
    echo "  customers: {$inserted}\n";

    // Address book entries for the first 2,000 (keeps the seed fast).
    $ids = Database::all(
        "SELECT id, name, phone FROM " . seed_table('users') . " WHERE role = 'customer' ORDER BY id DESC LIMIT " . min(2000, $inserted)
    );
    $addressRows = [];
    foreach ($ids as $user) {
        $address = seed_address((string) $user['name'], (string) ($user['phone'] ?? seed_phone()));
        $addressRows[] = [
            'user_id' => (int) $user['id'],
            'label' => 'Home',
            'full_name' => $address['full_name'],
            'phone' => $address['phone'],
            'line1' => $address['line1'],
            'line2' => $address['line2'],
            'city' => $address['city'],
            'district' => $address['district'],
            'postal_code' => $address['postal_code'],
            'country' => 'Sri Lanka',
            'is_default' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }
    seed_bulk('user_addresses', $addressRows);
    return $inserted;
}

/** Create $count realistic orders (items, events, payments) for seeded customers. */
function seed_orders(int $count): int
{
    if ($count <= 0) {
        return 0;
    }
    $products = Database::all(
        'SELECT id, title, slug, sku, image_url, price FROM ' . seed_table('products') . " WHERE status = 'active' ORDER BY RAND() LIMIT " . min(400, max(20, $count * 4))
    );
    $customers = Database::all(
        "SELECT id, name, email, phone FROM " . seed_table('users') . " WHERE role = 'customer' ORDER BY RAND() LIMIT " . min(500, max(10, $count))
    );
    if ($products === [] || $customers === []) {
        return 0;
    }

    $statuses = ['PENDING', 'CONFIRMED', 'PROCESSING', 'PACKED', 'SHIPPED', 'OUT_FOR_DELIVERY', 'DELIVERED', 'DELIVERED', 'DELIVERED', 'CANCELLED'];
    $methods = ['COD', 'COD', 'COD', 'BANK_TRANSFER', 'CARD_SEYLAN', 'KOKO', 'PAYZY', 'MINTPAY'];
    $inserted = 0;

    for ($i = 0; $i < $count; $i++) {
        $customer = seed_pick($customers);
        $status = seed_pick($statuses);
        $method = seed_pick($methods);
        $placed = date('Y-m-d H:i:s', strtotime('-' . mt_rand(0, 60) . ' days -' . mt_rand(0, 1400) . ' minutes'));
        $lineCount = mt_rand(1, 4);
        $items = [];
        $subtotal = 0.0;
        for ($line = 0; $line < $lineCount; $line++) {
            $product = seed_pick($products);
            $quantity = mt_rand(1, 3);
            $unit = (float) $product['price'];
            $total = $unit * $quantity;
            $subtotal += $total;
            $items[] = [$product, $quantity, $unit, $total];
        }
        $shippingFee = $subtotal >= 15000 ? 0.0 : 450.0;
        $discount = mt_rand(1, 100) <= 20 ? round($subtotal * 0.1, 2) : 0.0;
        $total = max(0.0, $subtotal + $shippingFee - $discount);
        $address = seed_address((string) $customer['name'], (string) ($customer['phone'] ?? seed_phone()));
        $paymentStatus = match ($status) {
            'DELIVERED', 'SHIPPED', 'OUT_FOR_DELIVERY' => 'PAID',
            'CANCELLED' => 'CANCELLED',
            'PENDING' => 'PENDING',
            default => $method === 'COD' ? 'UNPAID' : 'PENDING',
        };

        $orderUuid = uuid();
        $orderNumber = 'SEED-' . date('Y', strtotime($placed)) . '-' . str_pad((string) (100000 + $i), 6, '0', STR_PAD_LEFT);
        Database::insert('orders', [
            'uuid' => $orderUuid,
            'order_number' => $orderNumber,
            'user_id' => (int) $customer['id'],
            'customer_name' => (string) $customer['name'],
            'email' => (string) $customer['email'],
            'phone' => (string) ($customer['phone'] ?? seed_phone()),
            'status' => $status,
            'payment_status' => $paymentStatus,
            'payment_method' => $method,
            'subtotal' => $subtotal,
            'shipping_fee' => $shippingFee,
            'discount' => $discount,
            'coupon_code' => $discount > 0 ? 'WELCOME10' : null,
            'tax' => 0,
            'total' => $total,
            'currency' => 'LKR',
            'shipping_address' => json_encode($address, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'billing_address' => json_encode($address, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'placed_at' => $placed,
            'paid_at' => $paymentStatus === 'PAID' ? $placed : null,
            // orders has no created_at column — placed_at is the creation stamp.
            'updated_at' => $placed,
        ]);
        $orderId = (int) Database::pdo()->lastInsertId();

        foreach ($items as [$product, $quantity, $unit, $lineTotal]) {
            Database::insert('order_items', [
                'order_id' => $orderId,
                'product_id' => (int) $product['id'],
                'title' => (string) $product['title'],
                'slug' => (string) $product['slug'],
                'sku' => (string) $product['sku'],
                'image_url' => $product['image_url'],
                'unit_price' => $unit,
                'quantity' => $quantity,
                'total' => $lineTotal,
                'created_at' => $placed,
            ]);
        }

        Database::insert('payments', [
            'order_id' => $orderId,
            'gateway' => PaymentManager::keyForMethod($method),
            'amount' => $total,
            'currency' => 'LKR',
            'status' => $paymentStatus === 'PAID' ? 'paid' : ($paymentStatus === 'CANCELLED' ? 'failed' : 'pending'),
            'created_at' => $placed,
            'updated_at' => $placed,
        ]);

        Database::insert('order_events', [
            'order_id' => $orderId,
            'status' => 'PENDING',
            'message' => 'Order placed. We will confirm it shortly.',
            'actor_type' => 'customer',
            'actor_id' => (int) $customer['id'],
            'is_public' => 1,
            'created_at' => $placed,
        ]);
        if ($status !== 'PENDING') {
            Database::insert('order_events', [
                'order_id' => $orderId,
                'status' => $status,
                'message' => 'Order is now ' . strtolower(order_status_label($status)) . '.',
                'actor_type' => 'staff',
                'is_public' => 1,
                'created_at' => date('Y-m-d H:i:s', strtotime($placed . ' +' . mt_rand(30, 900) . ' minutes')),
            ]);
        }
        $inserted++;
    }
    echo "  orders: {$inserted}\n";
    return $inserted;
}

/* --------------------------------------------------------------------- CLI */

function seed_cli(array $argv): int
{
    $options = ['products' => 0, 'customers' => 0, 'orders' => 0, 'demo' => false, 'force' => false];
    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--')) {
            $pair = explode('=', substr($argument, 2), 2);
            $key = $pair[0];
            $value = $pair[1] ?? '1';
            if ($key === 'demo' || $key === 'force') {
                $options[$key] = true;
                continue;
            }
            if (array_key_exists($key, $options)) {
                $options[$key] = (int) $value;
            }
        }
    }

    if (!$options['force']) {
        $existing = Database::int('SELECT COUNT(*) FROM ' . seed_table('products'));
        if ($existing > 0) {
            fwrite(STDERR, "The catalogue already has {$existing} products. Pass --force to add more.\n");
            return 1;
        }
    }

    echo "SoftMyMarket seeder\n";
    $started = microtime(true);

    $categories = seed_catalog_tree();
    $brands = seed_catalog_brands();
    seed_catalog_coupons();
    echo '  categories: ' . count($categories) . "\n  brands: " . count($brands) . "\n";

    $products = $options['demo'] ? 120 : $options['products'];
    $customers = $options['demo'] ? 40 : $options['customers'];
    $orders = $options['orders'] > 0 ? $options['orders'] : ($options['demo'] ? 25 : 0);

    if ($products > 0) {
        seed_products($products, $categories, $brands);
    }
    if ($customers > 0) {
        seed_customers($customers);
    }
    if ($orders > 0) {
        seed_orders($orders);
    }

    Settings::set('seeded_at', date('Y-m-d H:i:s'));
    printf("Done in %.1f s.\n", microtime(true) - $started);
    echo "Demo customer password: Password123!\n";
    return 0;
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    exit(seed_cli($argv));
}

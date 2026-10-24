-- ===========================================================================
-- SoftMyMarket — optional demo catalogue
-- ===========================================================================
--
-- Import this AFTER database/install.sql if you want the storefront to look
-- populated straight away: 12 categories, 8 brands, 12 products and the 5 demo
-- coupons.
--
--   phpMyAdmin → database → Import → database/demo-data.sql
--
-- For the full catalogue (10,000 products, 10,000 customers) use the seeder
-- from the command line:
--   php tools/seed.php --products=10000 --customers=10000 --orders=250
-- or Admin → Tools → "Seed demo catalogue" in the dashboard.
--
-- Safe to run once. Re-running clones nothing (explicit ids + INSERT IGNORE).
-- ===========================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';

-- --------------------------------------------------------------- categories
INSERT IGNORE INTO `categories`
  (`id`, `parent_id`, `name`, `slug`, `icon`, `color`, `position`, `is_featured`, `is_active`, `depth`, `path`, `created_at`, `updated_at`)
VALUES
  (1, NULL, 'Mobiles & Tablets', 'mobiles-tablets', 'bi-phone', '#0c800f', 1, 1, 1, 0, '/', NOW(), NOW()),
  (2, NULL, 'Electronics', 'electronics', 'bi-headphones', '#0a57a0', 2, 1, 1, 0, '/', NOW(), NOW()),
  (3, NULL, 'Computers & Gaming', 'computers-gaming', 'bi-laptop', '#7c3aed', 3, 1, 1, 0, '/', NOW(), NOW()),
  (4, NULL, 'Home & Kitchen', 'home-kitchen', 'bi-house-door', '#b45309', 4, 1, 1, 0, '/', NOW(), NOW()),
  (5, NULL, 'Fashion', 'fashion', 'bi-bag', '#e11d48', 5, 1, 1, 0, '/', NOW(), NOW()),
  (6, NULL, 'Health & Beauty', 'health-beauty', 'bi-heart-pulse', '#0d9488', 6, 0, 1, 0, '/', NOW(), NOW()),
  (7, NULL, 'Grocery & Essentials', 'grocery-essentials', 'bi-basket', '#65a30d', 7, 0, 1, 0, '/', NOW(), NOW()),
  (8, '1', 'Smartphones', 'smartphones', 'bi-phone', '#0c800f', 1, 0, 1, 1, '/1/', NOW(), NOW()),
  (9, '2', 'Audio', 'audio', 'bi-headphones', '#0a57a0', 1, 0, 1, 1, '/2/', NOW(), NOW()),
  (10, '3', 'Laptops', 'laptops', 'bi-laptop', '#7c3aed', 1, 0, 1, 1, '/3/', NOW(), NOW()),
  (11, '4', 'Kitchen', 'kitchen', 'bi-cup-hot', '#b45309', 1, 0, 1, 1, '/4/', NOW(), NOW()),
  (12, '5', 'Footwear', 'footwear', 'bi-bag', '#e11d48', 1, 0, 1, 1, '/5/', NOW(), NOW());

-- ------------------------------------------------------------------ brands
INSERT IGNORE INTO `brands` (`id`, `name`, `slug`, `created_at`) VALUES
  (1, 'Samsung', 'samsung', NOW()),
  (2, 'Apple', 'apple', NOW()),
  (3, 'Sony', 'sony', NOW()),
  (4, 'Anker', 'anker', NOW()),
  (5, 'HP', 'hp', NOW()),
  (6, 'Nike', 'nike', NOW()),
  (7, 'Dilmah', 'dilmah', NOW()),
  (8, 'Abans', 'abans', NOW());

-- ---------------------------------------------------------------- products
INSERT IGNORE INTO `products`
  (`id`, `uuid`, `title`, `slug`, `sku`, `category_id`, `brand_id`, `short_description`, `description`,
   `price`, `compare_at_price`, `cost_price`, `stock`, `low_stock_alert`, `status`, `is_featured`,
   `is_flash_deal`, `free_shipping`, `shipping_fee`, `warranty`, `weight_grams`, `image_url`,
   `gallery`, `tags`, `specs`, `rating`, `review_count`, `sold_count`, `view_count`, `created_at`, `updated_at`)
VALUES
  (1, '505e1327-2fc5-43d6-94d1-2ff232e35fbb', 'Samsung Galaxy A55 5G (8GB/256GB)', 'samsung-galaxy-a55-5g', 'SMM-000001', 8, 1,
   'Super AMOLED 120Hz display, 50MP OIS camera and a 5000 mAh battery. Official Sri Lankan warranty.', 'Super AMOLED 120Hz display, 50MP OIS camera and a 5000 mAh battery. Official Sri Lankan warranty.',
   94900, '104900', 78000, 24, 5, 'active', 1,
   1, 1, 0, '1 year local warranty', 900, '/assets/img/demo/product-01.svg',
   '["/assets/img/demo/product-01.svg"]', 'new,popular', '{"Warranty":"1 year local warranty","Origin":"Imported","SKU":"SMM-000001"}', 4.5, 0, 0, 0, NOW(), NOW()),
  (2, 'b0b413ca-e44c-48a4-af02-fa59f4e3bbc2', 'Apple iPhone 15 (128GB)', 'apple-iphone-15-128gb', 'SMM-000002', 8, 2,
   'A16 Bionic chip, Dynamic Island and USB-C. Sealed box with 1-year local warranty.', 'A16 Bionic chip, Dynamic Island and USB-C. Sealed box with 1-year local warranty.',
   289900, '309900', 262000, 11, 5, 'active', 1,
   0, 1, 0, '1 year local warranty', 900, '/assets/img/demo/product-02.svg',
   '["/assets/img/demo/product-02.svg"]', 'new,popular', '{"Warranty":"1 year local warranty","Origin":"Imported","SKU":"SMM-000002"}', 4.5, 0, 0, 0, NOW(), NOW()),
  (3, '96eba049-b756-4746-8cef-e91c1ad0115b', 'Sony WH-1000XM5 Wireless Headphones', 'sony-wh-1000xm5', 'SMM-000003', 9, 3,
   'Industry-leading noise cancelling, 30-hour battery and multipoint Bluetooth.', 'Industry-leading noise cancelling, 30-hour battery and multipoint Bluetooth.',
   104900, '119900', 88000, 18, 5, 'active', 1,
   1, 1, 0, '1 year local warranty', 900, '/assets/img/demo/product-03.svg',
   '["/assets/img/demo/product-03.svg"]', 'new,popular', '{"Warranty":"1 year local warranty","Origin":"Imported","SKU":"SMM-000003"}', 4.5, 0, 0, 0, NOW(), NOW()),
  (4, 'ca871a54-9310-48ad-82ca-5efeaac19e0b', 'Anker Soundcore Life Q30 Headphones', 'anker-soundcore-life-q30', 'SMM-000004', 9, 4,
   'Hybrid ANC, 40-hour playtime and memory-foam ear cups.', 'Hybrid ANC, 40-hour playtime and memory-foam ear cups.',
   18900, '22900', 14200, 60, 5, 'active', 0,
   0, 1, 0, '1 year local warranty', 900, '/assets/img/demo/product-04.svg',
   '["/assets/img/demo/product-04.svg"]', 'new,popular', '{"Warranty":"1 year local warranty","Origin":"Imported","SKU":"SMM-000004"}', 4.5, 0, 0, 0, NOW(), NOW()),
  (5, '314d0cd6-130e-4767-9a12-d93fa9ac74bb', 'Anker 20000mAh Power Bank 20W', 'anker-20000mah-power-bank', 'SMM-000005', 2, 4,
   'Charge a phone four times over. USB-C PD 20W in and out.', 'Charge a phone four times over. USB-C PD 20W in and out.',
   8900, '10500', 6400, 120, 5, 'active', 0,
   0, 0, 0, '1 year local warranty', 900, '/assets/img/demo/product-05.svg',
   '["/assets/img/demo/product-05.svg"]', 'new,popular', '{"Warranty":"1 year local warranty","Origin":"Imported","SKU":"SMM-000005"}', 4.5, 0, 0, 0, NOW(), NOW()),
  (6, '266c39d4-d8bb-4944-b23d-4a3d856a212c', 'HP Pavilion 15 Core i5 12th Gen Laptop', 'hp-pavilion-15-i5-12th', 'SMM-000006', 10, 5,
   'i5-1235U, 16GB RAM, 512GB NVMe SSD, 15.6" FHD and Windows 11 Home.', 'i5-1235U, 16GB RAM, 512GB NVMe SSD, 15.6" FHD and Windows 11 Home.',
   239900, '259900', 216000, 9, 5, 'active', 1,
   0, 1, 0, '1 year local warranty', 900, '/assets/img/demo/product-06.svg',
   '["/assets/img/demo/product-06.svg"]', 'new,popular', '{"Warranty":"1 year local warranty","Origin":"Imported","SKU":"SMM-000006"}', 4.5, 0, 0, 0, NOW(), NOW()),
  (7, 'e085c134-a97f-4554-821f-5f61b72e598b', 'HP 250 G9 Celeron Laptop', 'hp-250-g9-celeron', 'SMM-000007', 10, 5,
   'Everyday laptop for study and office work. 8GB RAM, 256GB SSD.', 'Everyday laptop for study and office work. 8GB RAM, 256GB SSD.',
   114900, NULL, 99000, 15, 5, 'active', 0,
   0, 1, 0, '1 year local warranty', 900, '/assets/img/demo/product-07.svg',
   '["/assets/img/demo/product-07.svg"]', 'new,popular', '{"Warranty":"1 year local warranty","Origin":"Imported","SKU":"SMM-000007"}', 4.5, 0, 0, 0, NOW(), NOW()),
  (8, '75ba7a85-8f19-443c-a12b-d9ea7be81358', 'Sony Bravia 55" 4K Google TV', 'sony-bravia-55-4k-google-tv', 'SMM-000008', 2, 3,
   '4K HDR processor X1, Google TV with voice search, 3-year panel warranty.', '4K HDR processor X1, Google TV with voice search, 3-year panel warranty.',
   249900, '279900', 224000, 7, 5, 'active', 1,
   1, 1, 0, '1 year local warranty', 900, '/assets/img/demo/product-08.svg',
   '["/assets/img/demo/product-08.svg"]', 'new,popular', '{"Warranty":"1 year local warranty","Origin":"Imported","SKU":"SMM-000008"}', 4.5, 0, 0, 0, NOW(), NOW()),
  (9, '5243f86f-e689-4ae0-acef-3da312e05e0b', 'Abans 3-in-1 Blender 600W', 'abans-3-in-1-blender-600w', 'SMM-000009', 11, 8,
   'Blend, grind and juice with one motor. 2-year Abans warranty.', 'Blend, grind and juice with one motor. 2-year Abans warranty.',
   14900, '16900', 11200, 34, 5, 'active', 0,
   0, 0, 0, '1 year local warranty', 900, '/assets/img/demo/product-09.svg',
   '["/assets/img/demo/product-09.svg"]', 'new,popular', '{"Warranty":"1 year local warranty","Origin":"Imported","SKU":"SMM-000009"}', 4.5, 0, 0, 0, NOW(), NOW()),
  (10, 'ef98d5e2-a100-40db-87fc-7a144a6ea276', 'Abans Non-Stick Cookware Set (5 pcs)', 'abans-non-stick-cookware-set', 'SMM-000010', 11, 8,
   'Induction-compatible five-piece set with heat-resistant handles.', 'Induction-compatible five-piece set with heat-resistant handles.',
   9900, '12900', 7200, 41, 5, 'active', 0,
   0, 0, 0, '1 year local warranty', 900, '/assets/img/demo/product-10.svg',
   '["/assets/img/demo/product-10.svg"]', 'new,popular', '{"Warranty":"1 year local warranty","Origin":"Imported","SKU":"SMM-000010"}', 4.5, 0, 0, 0, NOW(), NOW()),
  (11, 'dbf47d91-c8d8-4be0-ba17-894c8a6658c8', 'Nike Revolution 7 Running Shoes', 'nike-revolution-7-running-shoes', 'SMM-000011', 12, 6,
   'Lightweight mesh upper with a soft foam midsole. Sizes UK 6–11.', 'Lightweight mesh upper with a soft foam midsole. Sizes UK 6–11.',
   19900, '23900', 14800, 52, 5, 'active', 1,
   0, 1, 0, '1 year local warranty', 900, '/assets/img/demo/product-11.svg',
   '["/assets/img/demo/product-11.svg"]', 'new,popular', '{"Warranty":"1 year local warranty","Origin":"Imported","SKU":"SMM-000011"}', 4.5, 0, 0, 0, NOW(), NOW()),
  (12, 'eef36441-481c-4aad-970f-f7685ddea940', 'Dilmah Ceylon Black Tea 400g', 'dilmah-ceylon-black-tea-400g', 'SMM-000012', 7, 7,
   'Single-origin Ceylon tea, foil-packed for freshness.', 'Single-origin Ceylon tea, foil-packed for freshness.',
   1850, '2100', 1350, 200, 5, 'active', 0,
   0, 0, 0, '1 year local warranty', 900, '/assets/img/demo/product-12.svg',
   '["/assets/img/demo/product-12.svg"]', 'new,popular', '{"Warranty":"1 year local warranty","Origin":"Imported","SKU":"SMM-000012"}', 4.5, 0, 0, 0, NOW(), NOW());

-- ---------------------------------------------------------------- coupons
INSERT IGNORE INTO `coupons`
  (`code`, `description`, `type`, `value`, `min_spend`, `max_discount`, `max_uses`, `used_count`,
   `per_user_limit`, `is_active`, `starts_at`, `expires_at`, `created_at`)
VALUES
  ('WELCOME10', '10% off your first order', 'percent', 10, 0, 5000, 2000, 0, 1, 1, DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_ADD(NOW(), INTERVAL 120 DAY), NOW()),
  ('SOFTDEAL15', '15% off orders over Rs 5,000', 'percent', 15, 5000, 7500, 1000, 0, 1, 1, DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_ADD(NOW(), INTERVAL 120 DAY), NOW()),
  ('FREESHIP', 'Free delivery on orders over Rs 3,000', 'free_shipping', 0, 3000, 900, 5000, 0, 1, 1, DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_ADD(NOW(), INTERVAL 120 DAY), NOW()),
  ('FLASH5K', 'Rs 5,000 off orders over Rs 40,000', 'fixed', 5000, 40000, 5000, 200, 0, 1, 1, DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_ADD(NOW(), INTERVAL 120 DAY), NOW()),
  ('AVURUDU20', 'Avurudu festival offer (expired)', 'percent', 20, 10000, 10000, 1, 0, 1, 0, DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_SUB(NOW(), INTERVAL 30 DAY), NOW());


SELECT 'categories' AS t, COUNT(*) AS n FROM `categories`
UNION ALL SELECT 'brands', COUNT(*) FROM `brands`
UNION ALL SELECT 'products', COUNT(*) FROM `products`
UNION ALL SELECT 'coupons', COUNT(*) FROM `coupons`;

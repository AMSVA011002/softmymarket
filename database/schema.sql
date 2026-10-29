-- ---------------------------------------------------------------------------
-- YourMarket — MySQL schema
--
-- Target: MySQL 5.7+/8.0 and MariaDB 10.3+, shared hosting friendly.
--
-- Setup: create an empty database in your hosting panel, then import this file
-- once in phpMyAdmin. It creates the tables and default settings without any
-- administrator or customer rows. The first public registration becomes admin;
-- all later public registrations are customers.
--
-- CREATE TABLE IF NOT EXISTS and guarded column upgrades make re-importing safe.
--
-- Conventions
--   * InnoDB everywhere, utf8mb4_unicode_ci so Sinhala/Tamil text is safe.
--   * Money is DECIMAL(12,2) — never FLOAT.
--   * Everything the app writes is parameter-bound; no dynamic SQL identifiers
--     come from user input (Database::identifier() whitelists table names).
--   * Addresses are stored denormalised on the order as JSON text so historical
--     invoices never change when a customer edits their address book.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;
SET time_zone = '+05:30';

-- --------------------------------------------------------------------- users
CREATE TABLE IF NOT EXISTS `users` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`              CHAR(36)        NOT NULL,
  `name`              VARCHAR(120)    NOT NULL,
  `email`             VARCHAR(160)    NOT NULL,
  `phone`             VARCHAR(30)     DEFAULT NULL,
  `password_hash`     VARCHAR(255)    NOT NULL,
  `role`              ENUM('admin','staff','customer') NOT NULL DEFAULT 'customer',
  `status`            ENUM('active','suspended','pending') NOT NULL DEFAULT 'active',
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
  `avatar_url`        VARCHAR(500)    DEFAULT NULL,
  `marketing_opt_in`  TINYINT(1)      NOT NULL DEFAULT 0,
  `email_verified_at` DATETIME        DEFAULT NULL,
  `last_login_at`     DATETIME        DEFAULT NULL,
  `last_login_ip`     VARCHAR(45)     DEFAULT NULL,
  `notes`             TEXT            DEFAULT NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_users_email` (`email`),
  UNIQUE KEY `uniq_users_uuid` (`uuid`),
  KEY `idx_users_role_status` (`role`,`status`),
  KEY `idx_users_created` (`created_at`),
  KEY `idx_users_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Re-import-safe upgrade for databases created before forced first-login changes.
SET @ym_has_password_change_flag = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
     AND COLUMN_NAME = 'must_change_password'
);
SET @ym_password_change_ddl = IF(
  @ym_has_password_change_flag = 0,
  'ALTER TABLE `users` ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`',
  'SELECT 1'
);
PREPARE ym_password_change_stmt FROM @ym_password_change_ddl;
EXECUTE ym_password_change_stmt;
DEALLOCATE PREPARE ym_password_change_stmt;

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `token_hash` CHAR(64)        NOT NULL,
  `expires_at` DATETIME        NOT NULL,
  `used_at`    DATETIME        DEFAULT NULL,
  `ip_address` VARCHAR(45)     DEFAULT NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_resets_token` (`token_hash`),
  KEY `idx_resets_user` (`user_id`),
  KEY `idx_resets_expiry` (`expires_at`),
  CONSTRAINT `fk_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_verifications` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at`    DATETIME DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_email_verification_token` (`token_hash`),
  KEY `idx_email_verifications_user` (`user_id`,`created_at`),
  KEY `idx_email_verifications_expiry` (`expires_at`),
  CONSTRAINT `fk_email_verifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_addresses` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `label`       VARCHAR(40)     NOT NULL DEFAULT 'Home',
  `full_name`   VARCHAR(120)    NOT NULL,
  `phone`       VARCHAR(30)     NOT NULL,
  `line1`       VARCHAR(180)    NOT NULL,
  `line2`       VARCHAR(180)    DEFAULT NULL,
  `city`        VARCHAR(80)     NOT NULL,
  `district`    VARCHAR(80)     NOT NULL,
  `postal_code` VARCHAR(20)     DEFAULT NULL,
  `country`     VARCHAR(80)     NOT NULL DEFAULT 'Sri Lanka',
  `is_default`  TINYINT(1)      NOT NULL DEFAULT 0,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_addresses_user` (`user_id`,`is_default`),
  CONSTRAINT `fk_addresses_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- categories
-- Adjacency list: unlimited depth. `path` is materialised as /1/5/12/ so a
-- subtree query is a single indexed LIKE instead of recursion.
CREATE TABLE IF NOT EXISTS `categories` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id`        BIGINT UNSIGNED DEFAULT NULL,
  `name`             VARCHAR(120)    NOT NULL,
  `slug`             VARCHAR(160)    NOT NULL,
  `description`      TEXT            DEFAULT NULL,
  `image_url`        VARCHAR(500)    DEFAULT NULL,
  `icon`             VARCHAR(60)     NOT NULL DEFAULT 'bi-grid',
  `color`            VARCHAR(20)     NOT NULL DEFAULT '#0c800f',
  `position`         INT             NOT NULL DEFAULT 0,
  `is_featured`      TINYINT(1)      NOT NULL DEFAULT 0,
  `is_active`        TINYINT(1)      NOT NULL DEFAULT 1,
  `depth`            INT             NOT NULL DEFAULT 0,
  `path`             VARCHAR(255)    NOT NULL DEFAULT '',
  `meta_title`       VARCHAR(180)    DEFAULT NULL,
  `meta_description` VARCHAR(320)    DEFAULT NULL,
  `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_categories_slug` (`slug`),
  KEY `idx_categories_parent` (`parent_id`,`position`),
  KEY `idx_categories_path` (`path`),
  KEY `idx_categories_active` (`is_active`,`is_featured`),
  CONSTRAINT `fk_categories_parent` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `brands` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(120)    NOT NULL,
  `slug`       VARCHAR(160)    NOT NULL,
  `logo_url`   VARCHAR(500)    DEFAULT NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_brands_slug` (`slug`),
  KEY `idx_brands_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ products
CREATE TABLE IF NOT EXISTS `products` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`              CHAR(36)        NOT NULL,
  `title`             VARCHAR(200)    NOT NULL,
  `slug`              VARCHAR(220)    NOT NULL,
  `sku`               VARCHAR(60)     NOT NULL,
  `category_id`       BIGINT UNSIGNED NOT NULL,
  `brand_id`          BIGINT UNSIGNED DEFAULT NULL,
  `short_description` VARCHAR(320)    DEFAULT NULL,
  `description`       LONGTEXT        DEFAULT NULL,
  `price`             DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `compare_at_price`  DECIMAL(12,2)   DEFAULT NULL,
  `cost_price`        DECIMAL(12,2)   DEFAULT NULL,
  `stock`             INT             NOT NULL DEFAULT 0,
  `low_stock_alert`   INT             NOT NULL DEFAULT 5,
  `condition`         ENUM('new','used','refurbished') NOT NULL DEFAULT 'new',
  `status`            ENUM('active','draft','archived') NOT NULL DEFAULT 'active',
  `is_choice`         TINYINT(1)      NOT NULL DEFAULT 0,
  `is_featured`       TINYINT(1)      NOT NULL DEFAULT 0,
  `is_flash_deal`     TINYINT(1)      NOT NULL DEFAULT 0,
  `free_shipping`     TINYINT(1)      NOT NULL DEFAULT 0,
  `shipping_fee`      DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `warranty`          VARCHAR(120)    DEFAULT NULL,
  `weight_grams`      INT             DEFAULT NULL,
  `image_url`         VARCHAR(1000)   DEFAULT NULL,
  `gallery`           LONGTEXT        DEFAULT NULL,
  `tags`              VARCHAR(500)    DEFAULT NULL,
  `specs`             LONGTEXT        DEFAULT NULL,
  `rating`            DECIMAL(3,2)    NOT NULL DEFAULT 0.00,
  `review_count`      INT             NOT NULL DEFAULT 0,
  `sold_count`        INT             NOT NULL DEFAULT 0,
  `view_count`        INT             NOT NULL DEFAULT 0,
  `meta_title`        VARCHAR(180)    DEFAULT NULL,
  `meta_description`  VARCHAR(320)    DEFAULT NULL,
  `deleted_at`        DATETIME        DEFAULT NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_products_uuid` (`uuid`),
  UNIQUE KEY `uniq_products_slug` (`slug`),
  UNIQUE KEY `uniq_products_sku` (`sku`),
  KEY `idx_products_category` (`category_id`,`status`),
  KEY `idx_products_brand` (`brand_id`),
  KEY `idx_products_status_created` (`status`,`deleted_at`,`created_at`),
  KEY `idx_products_price` (`price`),
  KEY `idx_products_featured` (`is_featured`,`status`),
  KEY `idx_products_deal` (`is_flash_deal`,`status`),
  KEY `idx_products_popular` (`sold_count`,`view_count`),
  KEY `idx_products_rating` (`rating`),
  KEY `idx_products_stock` (`stock`),
  CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_products_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Re-import-safe upgrade for existing product tables.
SET @ym_has_choice_flag = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
     AND COLUMN_NAME = 'is_choice'
);
SET @ym_choice_flag_ddl = IF(
  @ym_has_choice_flag = 0,
  'ALTER TABLE `products` ADD COLUMN `is_choice` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`',
  'SELECT 1'
);
PREPARE ym_choice_flag_stmt FROM @ym_choice_flag_ddl;
EXECUTE ym_choice_flag_stmt;
DEALLOCATE PREPARE ym_choice_flag_stmt;

-- Ensure SKU uniqueness also exists on databases upgraded from older schemas.
-- If legacy duplicate SKU values exist, MySQL rejects this ALTER rather than
-- silently changing identifiers that may be referenced by inventory workflows.
SET @ym_has_unique_sku = (
  SELECT COUNT(*) FROM (
    SELECT INDEX_NAME
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
     GROUP BY INDEX_NAME
    HAVING COUNT(*) = 1 AND MAX(COLUMN_NAME = 'sku') = 1 AND MAX(NON_UNIQUE) = 0
  ) AS ym_unique_sku_indexes
);
SET @ym_unique_sku_ddl = IF(
  @ym_has_unique_sku = 0,
  'ALTER TABLE `products` ADD UNIQUE KEY `uniq_products_sku` (`sku`)',
  'SELECT 1'
);
PREPARE ym_unique_sku_stmt FROM @ym_unique_sku_ddl;
EXECUTE ym_unique_sku_stmt;
DEALLOCATE PREPARE ym_unique_sku_stmt;

-- ------------------------------------------------------------------- reviews
CREATE TABLE IF NOT EXISTS `reviews` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`    BIGINT UNSIGNED NOT NULL,
  `user_id`       BIGINT UNSIGNED DEFAULT NULL,
  `rating`        TINYINT UNSIGNED NOT NULL DEFAULT 5,
  `title`         VARCHAR(120)    DEFAULT NULL,
  `body`          TEXT            NOT NULL,
  `author_name`   VARCHAR(120)    NOT NULL,
  `status`        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `helpful`       INT             NOT NULL DEFAULT 0,
  `admin_reply`   TEXT            DEFAULT NULL,
  `admin_reply_at` DATETIME       DEFAULT NULL,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_reviews_product_user` (`product_id`,`user_id`),
  KEY `idx_reviews_status` (`status`,`created_at`),
  KEY `idx_reviews_product` (`product_id`,`status`),
  CONSTRAINT `fk_reviews_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reviews_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------- cart
CREATE TABLE IF NOT EXISTS `cart_items` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `quantity`   INT             NOT NULL DEFAULT 1,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cart_user_product` (`user_id`,`product_id`),
  KEY `idx_cart_product` (`product_id`),
  CONSTRAINT `fk_cart_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cart_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wishlist_items` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_wishlist_user_product` (`user_id`,`product_id`),
  KEY `idx_wishlist_product` (`product_id`),
  CONSTRAINT `fk_wishlist_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wishlist_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------- orders
CREATE TABLE IF NOT EXISTS `orders` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`             CHAR(36)        NOT NULL,
  `order_number`     VARCHAR(40)     NOT NULL,
  `user_id`          BIGINT UNSIGNED DEFAULT NULL,
  `customer_name`    VARCHAR(120)    NOT NULL,
  `email`            VARCHAR(160)    NOT NULL,
  `phone`            VARCHAR(30)     NOT NULL,
  `status`           ENUM('PENDING','CONFIRMED','PROCESSING','PACKED','SHIPPED','OUT_FOR_DELIVERY','DELIVERED','CANCELLED','RETURNED','REFUNDED') NOT NULL DEFAULT 'PENDING',
  `payment_status`   ENUM('UNPAID','PENDING','PAID','PARTIAL','FAILED','REFUNDED','CANCELLED') NOT NULL DEFAULT 'UNPAID',
  `payment_method`   VARCHAR(30)     NOT NULL DEFAULT 'COD',
  `subtotal`         DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `shipping_fee`     DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `discount`         DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `tax`              DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `total`            DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `currency`         CHAR(3)         NOT NULL DEFAULT 'LKR',
  `coupon_code`      VARCHAR(40)     DEFAULT NULL,
  `coupon_id`        BIGINT UNSIGNED DEFAULT NULL,
  `shipping_address` LONGTEXT        DEFAULT NULL,
  `billing_address`  LONGTEXT        DEFAULT NULL,
  `note`             VARCHAR(500)    DEFAULT NULL,
  `receipt_url`      VARCHAR(500)    DEFAULT NULL,
  `tracking_number`  VARCHAR(80)     DEFAULT NULL,
  `carrier`          VARCHAR(80)     DEFAULT NULL,
  `courier_url`      VARCHAR(500)    DEFAULT NULL,
  `ip_address`       VARCHAR(45)     DEFAULT NULL,
  `user_agent`       VARCHAR(255)    DEFAULT NULL,
  `placed_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paid_at`          DATETIME        DEFAULT NULL,
  `confirmed_at`     DATETIME        DEFAULT NULL,
  `packed_at`        DATETIME        DEFAULT NULL,
  `shipped_at`       DATETIME        DEFAULT NULL,
  `delivered_at`     DATETIME        DEFAULT NULL,
  `cancelled_at`     DATETIME        DEFAULT NULL,
  `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_orders_uuid` (`uuid`),
  UNIQUE KEY `uniq_orders_number` (`order_number`),
  KEY `idx_orders_user` (`user_id`,`placed_at`),
  KEY `idx_orders_status` (`status`,`placed_at`),
  KEY `idx_orders_payment_status` (`payment_status`),
  KEY `idx_orders_payment_method` (`payment_method`),
  KEY `idx_orders_placed` (`placed_at`),
  KEY `idx_orders_email` (`email`),
  KEY `idx_orders_phone` (`phone`),
  KEY `idx_orders_coupon` (`coupon_id`),
  CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_items` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`   BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED DEFAULT NULL,
  `title`      VARCHAR(200)    NOT NULL,
  `slug`       VARCHAR(220)    DEFAULT NULL,
  `sku`        VARCHAR(60)     DEFAULT NULL,
  `image_url`  VARCHAR(1000)   DEFAULT NULL,
  `unit_price` DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `quantity`   INT             NOT NULL DEFAULT 1,
  `total`      DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_items_order` (`order_id`),
  KEY `idx_order_items_product` (`product_id`),
  CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_events` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`   BIGINT UNSIGNED NOT NULL,
  `status`     VARCHAR(30)     NOT NULL,
  `message`    VARCHAR(500)    NOT NULL DEFAULT '',
  `actor_type` VARCHAR(20)     NOT NULL DEFAULT 'system',
  `actor_id`   BIGINT UNSIGNED DEFAULT NULL,
  `is_public`  TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_events_order` (`order_id`,`id`),
  KEY `idx_events_status` (`status`),
  CONSTRAINT `fk_events_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------- customer returns
CREATE TABLE IF NOT EXISTS `return_requests` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`         BIGINT UNSIGNED NOT NULL,
  `user_id`          BIGINT UNSIGNED NOT NULL,
  `return_number`    VARCHAR(40) NOT NULL,
  `status`           ENUM('REQUESTED','APPROVED','IN_TRANSIT','RECEIVED','INSPECTING','REJECTED','REFUNDED','EXCHANGED','CLOSED','CANCELLED') NOT NULL DEFAULT 'REQUESTED',
  `reason`           ENUM('DAMAGED','DEFECTIVE','WRONG_ITEM','NOT_AS_DESCRIBED','CHANGED_MIND','OTHER') NOT NULL,
  `customer_details` TEXT NOT NULL,
  `evidence_file`    VARCHAR(120) DEFAULT NULL,
  `evidence_mime`    VARCHAR(80) DEFAULT NULL,
  `return_carrier`   VARCHAR(80) DEFAULT NULL,
  `return_tracking`  VARCHAR(120) DEFAULT NULL,
  `refund_amount`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `refund_method`    VARCHAR(40) DEFAULT NULL,
  `refund_reference` VARCHAR(120) DEFAULT NULL,
  `restocked_at`     DATETIME DEFAULT NULL,
  `reviewed_by`      BIGINT UNSIGNED DEFAULT NULL,
  `reviewed_at`      DATETIME DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_return_number` (`return_number`),
  KEY `idx_returns_order` (`order_id`,`created_at`),
  KEY `idx_returns_user` (`user_id`,`created_at`),
  KEY `idx_returns_status` (`status`,`created_at`),
  CONSTRAINT `fk_returns_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_returns_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_returns_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `return_request_items` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `return_request_id` BIGINT UNSIGNED NOT NULL,
  `order_item_id`     BIGINT UNSIGNED NOT NULL,
  `item_title`        VARCHAR(200) NOT NULL,
  `sku`               VARCHAR(60) DEFAULT NULL,
  `unit_price`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `quantity`          INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_return_order_item` (`return_request_id`,`order_item_id`),
  KEY `idx_return_items_order_item` (`order_item_id`),
  CONSTRAINT `fk_return_items_request` FOREIGN KEY (`return_request_id`) REFERENCES `return_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_return_items_order_item` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `return_events` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `return_request_id` BIGINT UNSIGNED NOT NULL,
  `status`            VARCHAR(20) NOT NULL,
  `message`           VARCHAR(500) NOT NULL DEFAULT '',
  `actor_type`        VARCHAR(20) NOT NULL DEFAULT 'system',
  `actor_id`          BIGINT UNSIGNED DEFAULT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_return_events_request` (`return_request_id`,`id`),
  CONSTRAINT `fk_return_events_request` FOREIGN KEY (`return_request_id`) REFERENCES `return_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_return_events_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ payments
CREATE TABLE IF NOT EXISTS `payments` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`          BIGINT UNSIGNED NOT NULL,
  `gateway`           VARCHAR(30)     NOT NULL,
  `gateway_reference` VARCHAR(120)    DEFAULT NULL,
  `amount`            DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `currency`          CHAR(3)         NOT NULL DEFAULT 'LKR',
  `status`            VARCHAR(20)     NOT NULL DEFAULT 'pending',
  `request_payload`   LONGTEXT        DEFAULT NULL,
  `response_payload`  LONGTEXT        DEFAULT NULL,
  `receipt_url`       VARCHAR(500)    DEFAULT NULL,
  `verified_by`       BIGINT UNSIGNED DEFAULT NULL,
  `verified_at`       DATETIME        DEFAULT NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payments_order` (`order_id`,`id`),
  KEY `idx_payments_reference` (`gateway_reference`),
  KEY `idx_payments_status` (`status`,`created_at`),
  KEY `idx_payments_gateway` (`gateway`,`status`),
  CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payments_verifier` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------- coupons
CREATE TABLE IF NOT EXISTS `coupons` (
  `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`                   VARCHAR(40)     NOT NULL,
  `description`            VARCHAR(190)    NOT NULL DEFAULT '',
  `type`                   ENUM('percent','fixed','free_shipping') NOT NULL DEFAULT 'percent',
  `value`                  DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `min_spend`              DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `max_discount`           DECIMAL(12,2)   DEFAULT NULL,
  `max_uses`               INT             NOT NULL DEFAULT 0,
  `used_count`             INT             NOT NULL DEFAULT 0,
  `per_user_limit`         INT             NOT NULL DEFAULT 1,
  `is_active`              TINYINT(1)      NOT NULL DEFAULT 1,
  `starts_at`              DATETIME        DEFAULT NULL,
  `expires_at`             DATETIME        DEFAULT NULL,
  `applies_to_category_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_coupons_code` (`code`),
  KEY `idx_coupons_active` (`is_active`,`expires_at`),
  CONSTRAINT `fk_coupons_category` FOREIGN KEY (`applies_to_category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coupon_redemptions` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `coupon_id`  BIGINT UNSIGNED NOT NULL,
  `user_id`    BIGINT UNSIGNED DEFAULT NULL,
  `order_id`   BIGINT UNSIGNED DEFAULT NULL,
  `amount`     DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_redemptions_coupon` (`coupon_id`,`created_at`),
  KEY `idx_redemptions_user` (`user_id`,`coupon_id`),
  KEY `idx_redemptions_order` (`order_id`),
  CONSTRAINT `fk_redemptions_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_redemptions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_redemptions_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ stock log
CREATE TABLE IF NOT EXISTS `inventory_movements` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `change_qty` INT             NOT NULL,
  `reason`     VARCHAR(40)     NOT NULL DEFAULT 'adjustment',
  `reference`  VARCHAR(80)     DEFAULT NULL,
  `note`       VARCHAR(255)    DEFAULT NULL,
  `admin_id`   BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_movements_product` (`product_id`,`id`),
  KEY `idx_movements_reason` (`reason`,`created_at`),
  CONSTRAINT `fk_movements_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_movements_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------ notifications
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `title`      VARCHAR(160)    NOT NULL,
  `body`       VARCHAR(500)    NOT NULL DEFAULT '',
  `type`       VARCHAR(20)     NOT NULL DEFAULT 'info',
  `link`       VARCHAR(255)    DEFAULT NULL,
  `read_at`    DATETIME        DEFAULT NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user` (`user_id`,`read_at`,`created_at`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ settings
CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key`   VARCHAR(80) NOT NULL,
  `setting_value` TEXT        DEFAULT NULL,
  `updated_at`    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------- rate limits
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `bucket`       VARCHAR(40) NOT NULL,
  `identifier`   VARCHAR(64) NOT NULL,
  `hits`         INT         NOT NULL DEFAULT 0,
  `window_start` DATETIME    NOT NULL,
  `expires_at`   DATETIME    NOT NULL,
  PRIMARY KEY (`bucket`,`identifier`),
  KEY `idx_rate_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- audit trail
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED DEFAULT NULL,
  `action`     VARCHAR(80)     NOT NULL,
  `entity`     VARCHAR(60)     NOT NULL DEFAULT 'system',
  `entity_id`  VARCHAR(60)     DEFAULT NULL,
  `meta`       LONGTEXT        DEFAULT NULL,
  `ip_address` VARCHAR(45)     DEFAULT NULL,
  `user_agent` VARCHAR(255)    DEFAULT NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_action` (`action`,`created_at`),
  KEY `idx_audit_user` (`user_id`,`created_at`),
  KEY `idx_audit_entity` (`entity`,`entity_id`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------- newsletter
CREATE TABLE IF NOT EXISTS `newsletter_subscribers` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`      VARCHAR(160)    NOT NULL,
  `source`     VARCHAR(40)     NOT NULL DEFAULT 'footer',
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_newsletter_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------ contact inbox
CREATE TABLE IF NOT EXISTS `contact_messages` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(120)    NOT NULL,
  `email`      VARCHAR(160)    NOT NULL,
  `phone`      VARCHAR(30)     DEFAULT NULL,
  `subject`    VARCHAR(160)    NOT NULL DEFAULT '',
  `message`    TEXT            NOT NULL,
  `topic`      VARCHAR(40)     NOT NULL DEFAULT 'general',
  `status`     ENUM('new','open','closed','spam') NOT NULL DEFAULT 'new',
  `ip_address` VARCHAR(45)     DEFAULT NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_contact_status` (`status`,`created_at`),
  KEY `idx_contact_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------- search terms
CREATE TABLE IF NOT EXISTS `search_log` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `term`       VARCHAR(120)    NOT NULL,
  `results`    INT             NOT NULL DEFAULT 0,
  `user_id`    BIGINT UNSIGNED DEFAULT NULL,
  `ip_address` VARCHAR(45)     DEFAULT NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_search_term` (`term`,`created_at`),
  KEY `idx_search_empty` (`results`,`created_at`),
  CONSTRAINT `fk_search_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Default settings. Settings::get() already ships these as PHP defaults, but
-- having rows means the admin settings screen opens with real values.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('store_name', 'YourMarket'),
  ('store_tagline', 'Sri Lanka''s friendliest online store'),
  ('store_description', 'Shop quality products from one trusted local store, with reliable delivery and helpful customer support.'),
  ('store_language', 'en-LK'),
  ('store_locality', 'Colombo'),
  ('store_country', 'Sri Lanka'),
  ('store_country_code', 'LK'),
  ('store_hours', ''),
  ('delivery_promise', '1–4 working days'),
  ('support_response_time', 'within one business day'),
  ('social_image_url', ''),
  ('faq_items', ''),
  ('return_window_days', '7'),
  ('trust_delivery_title', 'Island-wide delivery'),
  ('trust_delivery_text', 'Delivery times are shown at checkout'),
  ('trust_payment_title', 'Flexible payments'),
  ('trust_payment_text', 'Available methods appear at checkout'),
  ('trust_quality_title', 'Carefully selected products'),
  ('trust_quality_text', 'Product details shown on each listing'),
  ('trust_returns_title', 'Customer care'),
  ('trust_returns_text', 'Contact our team if you need help'),
  ('support_email', 'hello@yourmarket.example'),
  ('orders_email', 'orders@yourmarket.example'),
  ('support_phone', '+94 11 234 5678'),
  ('support_whatsapp', '+94 77 234 5678'),
  ('address', '128 Galle Road, Colombo 03, Sri Lanka'),
  ('currency', 'LKR'),
  ('currency_symbol', 'Rs'),
  ('order_prefix', 'YM'),
  ('default_shipping_fee', '450'),
  ('free_shipping_threshold', '15000'),
  ('tax_percent', '0'),
  ('low_stock_threshold', '5'),
  ('allow_guest_checkout', '1'),
  ('live_tracking_refresh', '30'),
  ('tracking_note', 'Tracking updates often. Keep this page open and it will refresh by itself.'),
  ('bank_transfer_note', 'Transfer to Seylan Bank · 0123456789 · YourMarket (Pvt) Ltd, and upload your slip so we can verify it quickly.'),
  ('default_meta_description', '');


-- Seed missing settings only; existing admin-customized values are preserved.
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('store_name', 'YourMarket'),
  ('store_tagline', 'Sri Lanka''s friendliest online store'),
  ('store_description', 'Shop quality products from one trusted local store, with reliable delivery and helpful customer support.'),
  ('store_language', 'en-LK'),
  ('store_locality', 'Colombo'),
  ('store_country', 'Sri Lanka'),
  ('store_country_code', 'LK'),
  ('store_hours', ''),
  ('delivery_promise', '1–4 working days'),
  ('support_response_time', 'within one business day'),
  ('social_image_url', ''),
  ('facebook_url', ''),
  ('instagram_url', ''),
  ('tiktok_url', ''),
  ('youtube_url', ''),
  ('twitter_url', ''),
  ('whatsapp_url', ''),
  ('linkedin_url', ''),
  ('pinterest_url', ''),
  ('snapchat_url', ''),
  ('telegram_url', ''),
  ('discord_url', ''),
  ('threads_url', ''),
  ('reddit_url', ''),
  ('twitch_url', ''),
  ('messenger_url', ''),
  ('wechat_url', ''),
  ('line_url', ''),
  ('mastodon_url', ''),
  ('vimeo_url', ''),
  ('spotify_url', ''),
  ('medium_url', ''),
  ('behance_url', ''),
  ('wordpress_url', ''),
  ('github_url', ''),
  ('footer_copyright', '© {year} {store}. All rights reserved.'),
  ('product_placeholder_url', ''),
  ('faq_items', ''),
  ('return_window_days', '7'),
  ('trust_delivery_title', 'Island-wide delivery'),
  ('trust_delivery_text', 'Delivery times are shown at checkout'),
  ('trust_payment_title', 'Flexible payments'),
  ('trust_payment_text', 'Available methods appear at checkout'),
  ('trust_quality_title', 'Carefully selected products'),
  ('trust_quality_text', 'Product details shown on each listing'),
  ('trust_returns_title', 'Customer care'),
  ('trust_returns_text', 'Contact our team if you need help'),
  ('support_email', 'hello@yourmarket.example'),
  ('orders_email', 'orders@yourmarket.example'),
  ('support_phone', '+94 11 234 5678'),
  ('support_whatsapp', '+94 77 234 5678'),
  ('address', '128 Galle Road, Colombo 03, Sri Lanka'),
  ('currency', 'LKR'),
  ('currency_symbol', 'Rs'),
  ('order_prefix', 'YM'),
  ('default_shipping_fee', '450'),
  ('free_shipping_threshold', '15000'),
  ('tax_percent', '0'),
  ('low_stock_threshold', '5'),
  ('allow_guest_checkout', '1'),
  ('live_tracking_refresh', '30'),
  ('tracking_note', 'Tracking updates often. Keep this page open and it will refresh by itself.'),
  ('bank_transfer_note', 'Transfer to Seylan Bank · 0123456789 · YourMarket (Pvt) Ltd, and upload your slip so we can verify it quickly.'),
  ('default_meta_description', '');

-- Convert only untouched legacy defaults on existing installations.
-- Replace only untouched former product defaults with the YourMarket defaults.
-- Existing custom values are deliberately preserved. Back up before applying.
UPDATE `settings` SET `setting_value` = 'YourMarket'
 WHERE `setting_key` = 'store_name' AND `setting_value` = 'SoftMyMarket';

UPDATE `settings` SET `setting_value` = 'hello@yourmarket.example'
 WHERE `setting_key` = 'support_email'
   AND `setting_value` = 'hello@softmymarket.lk';

UPDATE `settings` SET `setting_value` = 'orders@yourmarket.example'
 WHERE `setting_key` = 'orders_email' AND `setting_value` = 'orders@softmymarket.lk';

UPDATE `settings` SET `setting_value` = ''
 WHERE `setting_key` = 'mail_from_name' AND `setting_value` = 'SoftMyMarket';

UPDATE `settings` SET `setting_value` = 'Welcome — delivery options and order details are shown at checkout.'
 WHERE `setting_key` = 'announcement'
   AND `setting_value` IN ('Free delivery on orders over Rs 15,000 — cash on delivery island-wide.', 'Island-wide delivery in 1–4 days · Free shipping over Rs 15,000');

UPDATE `settings` SET `setting_value` = 'Thank you for shopping with us.'
 WHERE `setting_key` = 'footer_note'
   AND `setting_value` = 'SoftMyMarket is a single-vendor marketplace operated in Colombo, Sri Lanka.';

UPDATE `settings` SET `setting_value` = ''
 WHERE `setting_key` = 'default_meta_title'
   AND `setting_value` = 'SoftMyMarket — online shopping in Sri Lanka';

UPDATE `settings` SET `setting_value` = ''
 WHERE `setting_key` = 'default_meta_description'
   AND `setting_value` = 'Shop electronics, fashion, home and grocery with cash on delivery, card payments and island-wide tracking.';

UPDATE `settings` SET `setting_value` = 'YM'
 WHERE `setting_key` = 'order_prefix' AND `setting_value` = 'SMM';

-- Existing store text may use the old brand in this exact shipped default note.
UPDATE `settings`
   SET `setting_value` = 'Transfer to Seylan Bank · 0123456789 · YourMarket (Pvt) Ltd, and upload your slip so we can verify it quickly.'
 WHERE `setting_key` = 'bank_transfer_note'
   AND `setting_value` = 'Transfer to Seylan Bank · 0123456789 · SoftMyMarket (Pvt) Ltd, and upload your slip so we can verify it quickly.';

-- Remove legacy brand-specific social defaults only if they are still untouched.
UPDATE `settings` SET `setting_value` = ''
 WHERE (`setting_key` = 'facebook_url' AND `setting_value` = 'https://facebook.com/softmymarket')
    OR (`setting_key` = 'instagram_url' AND `setting_value` = 'https://instagram.com/softmymarket');

-- SoftMyMarket return request system migration (non-destructive).
-- Apply once to an existing installation after taking a verified database backup.
SET NAMES utf8mb4;
SET time_zone = '+05:30';

-- ----------------------------------------------------------- customer returns
CREATE TABLE `return_requests` (
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

CREATE TABLE `return_request_items` (
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

CREATE TABLE `return_events` (
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


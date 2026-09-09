START TRANSACTION;

-- Add newsletter_throttle table for global source-based rate limiting
SET @tableExists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'newsletter_throttle'
);

SET @sql := IF(
  @tableExists = 0,
  'CREATE TABLE `newsletter_throttle` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `source_key` varchar(255) NOT NULL COMMENT ''HMAC-derived source identifier (IP, forwarded header hash, etc)'',
    `request_count` int(11) NOT NULL DEFAULT 1 COMMENT ''Number of requests from this source in current window'',
    `window_start` datetime NOT NULL COMMENT ''Start of current throttle window'',
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `source_key` (`source_key`),
    KEY `window_start` (`window_start`),
    KEY `created_at` (`created_at`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''Global source-based throttle for newsletter subscriptions''',
  'SELECT "Table newsletter_throttle already exists" AS message'
);

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add verification_token_expires_at column if missing
SET @columnExists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'newsletter_subscribers' AND COLUMN_NAME = 'verification_token_expires_at'
);

SET @sql := IF(
  @columnExists = 0,
  'ALTER TABLE `newsletter_subscribers` ADD COLUMN `verification_token_expires_at` DATETIME NULL DEFAULT NULL COMMENT ''Token expiry timestamp for double opt-in'' AFTER `verification_token`',
  'SELECT "Column verification_token_expires_at already exists" AS message'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add index on verification_token for efficient lookup during verification
SET @indexExists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'newsletter_subscribers' AND INDEX_NAME = 'idx_verification_token'
);

SET @sql := IF(
  @indexExists = 0,
  'CREATE INDEX `idx_verification_token` ON `newsletter_subscribers` (`verification_token`)',
  'SELECT "Index idx_verification_token already exists" AS message'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Note: email UNIQUE KEY already created in migration 12, do not duplicate
-- Index on verification_token_expires_at for throttle lookups
SET @indexExists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'newsletter_subscribers' AND INDEX_NAME = 'idx_verification_token_expires_at'
);

SET @sql := IF(
  @indexExists = 0,
  'CREATE INDEX `idx_verification_token_expires_at` ON `newsletter_subscribers` (`verification_token_expires_at`)',
  'SELECT "Index idx_verification_token_expires_at already exists" AS message'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;

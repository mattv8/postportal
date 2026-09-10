-- Migration 22: Add authentication security tables (SEC-09, SEC-10, SEC-11)
-- Idempotent migration for session rotation, login throttling, and account activation

-- Table for tracking login attempts by source using the derived login-throttle key (server-side throttling)
-- SEC-10: Two independent scopes - track source and username separately
-- source_hmac: HMAC-SHA-256(derived login-throttle key, source_ip) prevents IP enumeration
-- username_hmac: HMAC-SHA-256(derived login-throttle key, normalize(username)) prevents username enumeration
-- Each scope is independent: rotating usernames from one IP throttles that IP; same user from many IPs throttles that user

-- Drop old composite table if exists (migration from previous schema)
DROP TABLE IF EXISTS `login_attempts`;

-- Create source-based throttling table
CREATE TABLE IF NOT EXISTS `login_attempts_by_source` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `source_hmac` BINARY(32) NOT NULL COMMENT 'HMAC-SHA-256 of client source IP',
  `attempt_count` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Failed attempts from this source',
  `last_attempt_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL COMMENT 'Automatic retention window expiry',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_source` (`source_hmac`) COMMENT 'One record per source',
  KEY `idx_expires_at` (`expires_at`) COMMENT 'Efficient cleanup'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Failed login attempt tracking by source IP (independent scope)';

-- Create username-based throttling table
CREATE TABLE IF NOT EXISTS `login_attempts_by_username` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `username_hmac` BINARY(32) NOT NULL COMMENT 'HMAC-SHA-256 of normalized username',
  `attempt_count` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Failed attempts for this username',
  `last_attempt_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL COMMENT 'Automatic retention window expiry',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_username` (`username_hmac`) COMMENT 'One record per username',
  KEY `idx_expires_at` (`expires_at`) COMMENT 'Efficient cleanup'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Failed login attempt tracking by username (independent scope)';

-- Table for account activation tokens
-- SEC-11: One-time, hashed, purpose-bound activation tokens with short expiry
CREATE TABLE IF NOT EXISTS `activation_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(32) NOT NULL COMMENT 'Username being activated',
  `token_hash` BINARY(32) NOT NULL COMMENT 'SHA-256 hash of the raw token (never store plaintext)',
  `purpose` VARCHAR(32) NOT NULL DEFAULT 'activate' COMMENT 'Purpose: activate, reset_password, confirm_email, etc.',
  `expires_at` DATETIME NOT NULL COMMENT 'Token expiry time',
  `consumed_at` DATETIME NULL COMMENT 'When token was redeemed; NULL if unused',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_token_hash` (`token_hash`),
  KEY `idx_username_purpose` (`username`, `purpose`),
  KEY `idx_expires_at` (`expires_at`),
  KEY `idx_consumed_at` (`consumed_at`),
  FOREIGN KEY (`username`) REFERENCES `users`(`username`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='One-time activation and reset tokens; stores only hash and expiry';

<?php

declare(strict_types=1);

namespace PostPortal\Security;

/**
 * HMAC-SHA-256 privacy key generation for throttling and tracking
 * SEC-10: Derives privacy-minimized, non-reversible scope keys from sensitive values
 *
 * Uses a derived application key to ensure:
 * - Keys cannot be reversed to recover original values
 * - Different servers produce different keys for same input
 * - Collisions are cryptographically unlikely
 */
class HmacPrivacyKey
{
    /**
     * Generate HMAC-SHA-256 key for login attempt tracking.
     * Uses the login-throttle-derived application key to prevent value recovery.
     *
     * @param string $value The value to hash (IP address or username)
     * @return string HMAC-SHA-256 output (binary, 32 bytes)
     */
    public static function generateThrottleKey(string $value): string
    {
        return hash_hmac('sha256', $value, AppKeyDeriver::loginThrottleHmacKey(), true);
    }
}

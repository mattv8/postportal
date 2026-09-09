<?php

declare(strict_types=1);

namespace PostPortal\Security;

/**
 * HMAC-SHA-256 privacy key generation for throttling and tracking
 * SEC-10: Derives privacy-minimized, non-reversible scope keys from sensitive values
 *
 * Uses server secret (from environment or database) to ensure:
 * - Keys cannot be reversed to recover original values
 * - Different servers produce different keys for same input
 * - Collisions are cryptographically unlikely
 */
class HmacPrivacyKey
{
    /**
     * Generate HMAC-SHA-256 key for login attempt tracking.
     * Uses rotating server secret to prevent value recovery.
     *
     * @param string $value The value to hash (IP address or username)
     * @return string HMAC-SHA-256 output (binary, 32 bytes)
     */
    public static function generateThrottleKey(string $value): string
    {
        $secret = self::getServerSecret();
        return hash_hmac('sha256', $value, $secret, true);
    }

    /**
     * Get the server secret for HMAC operations.
     * Requires APP_ENCRYPTION_KEY from Workstream 0 (generated at deployment).
     * Fails safely with logged error if not configured.
     *
     * @return string Server secret (32+ bytes)
     */
    private static function getServerSecret(): string
    {
        // Required: APP_ENCRYPTION_KEY (Workstream 0 generates and sets)
        $secret = getenv('APP_ENCRYPTION_KEY') ?: '';

        if ($secret === '' || strlen($secret) < 32) {
            $error = 'Configuration error: APP_ENCRYPTION_KEY not set or too short (require >=32 bytes). Login throttling disabled.';
            error_log($error);
            // Fail safely: throw to prevent login without throttling
            throw new \RuntimeException($error);
        }

        return $secret;
    }
}

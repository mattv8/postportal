<?php

declare(strict_types=1);

namespace PostPortal\Repository;

/**
 * Interface for user repository operations
 */
interface UserRepositoryInterface
{
    /**
     * Get all users
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAll(): array;

    /**
     * Get a user by username
     *
     * @param string $username
     * @return array<string, mixed>|null
     */
    public function getByUsername(string $username): ?array;

    /**
     * Create a new user
     *
     * @param array<string, mixed> $data User data
     * @return bool True on success
     */
    public function create(array $data): bool;

    /**
     * Update a user attribute
     *
     * @param string $username
     * @param string $field
     * @param mixed $value
     * @return bool True on success
     */
    public function updateAttribute(string $username, string $field, mixed $value): bool;

    /**
     * Update multiple user attributes at once
     *
     * @param string $username
     * @param array<string, mixed> $data
     * @return bool True on success
     */
    public function update(string $username, array $data): bool;

    /**
     * Delete a user
     *
     * @param string $username
     * @return bool True on success
     */
    public function delete(string $username): bool;

    /**
     * Check if a username already exists
     *
     * @param string $username
     * @return bool
     */
    public function exists(string $username): bool;

    /**
     * Issue an activation token for a user.
     * Returns the raw token once; stores only hash and expiry.
     * SEC-11: Single-use, hashed, purpose-bound tokens.
     *
     * @param string $username
     * @return array{success: bool, token?: string, expires_at?: string, error?: string}
     */
    public function issueActivationToken(string $username): array;

    /**
     * Redeem an activation token and set a new password.
     * Atomically marks token as consumed and updates user password.
     * SEC-11: Atomic, single-use token consumption.
     *
     * @param string $username
     * @param string $rawToken The raw token (will be hashed for comparison)
     * @param string $newPassword The new password to set
     * @return array{success: bool, error?: string}
     */
    public function redeemActivationToken(string $username, string $rawToken, string $newPassword): array;

    /**
     * Check if a user has exceeded login attempt threshold.
     * SEC-10: Server-side throttling with privacy-minimized keys.
     * Keys are HMAC-SHA-256 derived from raw values by implementation.
     *
     * @param string $sourceKey Raw client source identifier (IP address, etc.)
     * @param string $usernameKey Raw normalized username (lowercase)
     * @return array{allowed: bool, retry_after?: int}
     */
    public function checkLoginThrottle(string $sourceKey, string $usernameKey): array;

    /**
     * Record a failed login attempt.
     * SEC-10: Track attempts for throttling with HMAC privacy.
     * Keys are derived internally and cannot be reversed.
     *
     * @param string $sourceKey Raw client source identifier (IP address)
     * @param string $usernameKey Raw normalized username (lowercase)
     * @return bool
     */
    public function recordLoginAttempt(string $sourceKey, string $usernameKey): bool;

    /**
     * Clear login attempts for a user/source after successful login.
     * SEC-10: Decay after success to allow recovery.
     *
     * @param string $sourceKey Raw client source identifier
     * @param string $usernameKey Raw normalized username
     * @return bool
     */
    public function clearLoginAttempts(string $sourceKey, string $usernameKey): bool;
}

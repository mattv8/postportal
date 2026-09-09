<?php

declare(strict_types=1);

namespace PostPortal\Service;

/**
 * Interface for user service operations
 */
interface UserServiceInterface
{
    /**
     * Get all users
     *
     * @return array{success: bool, data?: array<int, array<string, mixed>>, error?: string}
     */
    public function getAllUsers(): array;

    /**
     * Get a user by username
     *
     * @param string $username
     * @return array{success: bool, data?: array<string, mixed>, error?: string}
     */
    public function getUser(string $username): array;

    /**
     * Create a new user
     *
     * @param array<string, mixed> $data
     * @return array{success: bool, message?: string, error?: string}
     */
    public function createUser(array $data): array;

    /**
     * Update a single user attribute
     *
     * @param string $username
     * @param string $field
     * @param mixed $value
     * @return array{success: bool, message?: string, error?: string}
     */
    public function updateUserAttribute(string $username, string $field, mixed $value): array;

    /**
     * Update multiple user attributes
     *
     * @param string $username
     * @param array<string, mixed> $data
     * @return array{success: bool, message?: string, error?: string}
     */
    public function updateUser(string $username, array $data): array;

    /**
     * Delete a user
     *
     * @param string $username
     * @param string $currentUser The currently logged-in user (to prevent self-deletion)
     * @return array{success: bool, message?: string, error?: string}
     */
    public function deleteUser(string $username, string $currentUser): array;

    /**
     * Issue a one-time activation token for a user.
     * Returns the raw token once; only the hash and expiry are persisted.
     * SEC-11: Account activation with single-use, hashed, time-bound tokens.
     *
     * @param string $username
     * @return array{success: bool, token?: string, expires_at?: string, error?: string}
     */
    public function issueActivationToken(string $username): array;
}

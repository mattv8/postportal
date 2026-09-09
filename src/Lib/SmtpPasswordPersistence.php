<?php

declare(strict_types=1);

namespace PostPortal\Lib;

/**
 * Injectable persistence boundary for SMTP password migration.
 * Provides granular transaction primitives; does not contain migration logic.
 * Allows testing without a shared database.
 */
interface SmtpPasswordPersistence
{
    /**
     * Fetch all records with non-empty SMTP password.
     *
     * @return array<array{id: int, smtp_password: string}>
     * @throws \RuntimeException On database error (generic 'Database error' message)
     */
    public function fetchAllPasswords(): array;

    /**
     * Begin a database transaction.
     *
     * @throws \RuntimeException On database error
     */
    public function beginTransaction(): void;

    /**
     * Lock a password record for update and return its current ciphertext.
     *
     * @param int $id Record ID
     * @return string|null Current ciphertext, or null if not found
     * @throws \RuntimeException On database error
     */
    public function lockPassword(int $id): ?string;

    /**
     * Write a new ciphertext to a password record.
     *
     * @param int $id Record ID
     * @param string $ciphertext New base64-encoded ciphertext
     * @throws \RuntimeException On database error
     */
    public function writePassword(int $id, string $ciphertext): void;

    /**
     * Read the current ciphertext from a password record.
     *
     * @param int $id Record ID
     * @return string|null Current ciphertext, or null if not found
     * @throws \RuntimeException On database error
     */
    public function readPassword(int $id): ?string;

    /**
     * Commit the active transaction.
     *
     * @throws \RuntimeException On database error
     */
    public function commit(): void;

    /**
     * Rollback the active transaction, restoring original state.
     *
     * @throws \RuntimeException On database error (should be swallowed and logged generically)
     */
    public function rollback(): void;
}

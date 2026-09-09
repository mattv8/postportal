<?php

declare(strict_types=1);

namespace PostPortal\Lib;

use mysqli;

/**
 * Production mysqli implementation of SmtpPasswordPersistence.
 * Provides granular transaction primitives; migration orchestration lives in caller.
 */
class SmtpPasswordPersistenceMysqli implements SmtpPasswordPersistence
{
    private mysqli $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    /**
     * Fetch all records with non-empty SMTP password.
     *
     * @throws \RuntimeException On database error (generic message only)
     */
    public function fetchAllPasswords(): array
    {
        try {
            $stmt = $this->conn->prepare(
                'SELECT id, smtp_password FROM settings WHERE smtp_password IS NOT NULL AND smtp_password <> ""'
            );
            if (!$stmt) {
                throw new \RuntimeException('Database error');
            }

            if (!$stmt->execute()) {
                throw new \RuntimeException('Database error');
            }

            $result = $stmt->get_result();
            if (!$result) {
                throw new \RuntimeException('Database error');
            }

            $records = $result->fetch_all(\MYSQLI_ASSOC);
            $stmt->close();

            return $records;

        } catch (\Throwable $e) {
            if ($e instanceof \RuntimeException && $e->getMessage() === 'Database error') {
                throw $e;
            }
            throw new \RuntimeException('Database error');
        }
    }

    /**
     * Begin a database transaction.
     *
     * @throws \RuntimeException On database error
     */
    public function beginTransaction(): void
    {
        try {
            if (!$this->conn->begin_transaction()) {
                throw new \RuntimeException('Database error');
            }
        } catch (\Throwable $e) {
            if ($e instanceof \RuntimeException && $e->getMessage() === 'Database error') {
                throw $e;
            }
            throw new \RuntimeException('Database error');
        }
    }

    /**
     * Lock a password record for update and return its current ciphertext.
     *
     * @throws \RuntimeException On database error
     */
    public function lockPassword(int $id): ?string
    {
        try {
            $stmt = $this->conn->prepare(
                'SELECT smtp_password FROM settings WHERE id = ? FOR UPDATE'
            );
            if (!$stmt) {
                throw new \RuntimeException('Database error');
            }

            if (!$stmt->bind_param('i', $id)) {
                $stmt->close();
                throw new \RuntimeException('Database error');
            }

            if (!$stmt->execute()) {
                $stmt->close();
                throw new \RuntimeException('Database error');
            }

            $result = $stmt->get_result();
            if (!$result) {
                $stmt->close();
                throw new \RuntimeException('Database error');
            }

            $row = $result->fetch_assoc();
            $stmt->close();

            return $row ? $row['smtp_password'] : null;

        } catch (\Throwable $e) {
            if ($e instanceof \RuntimeException && $e->getMessage() === 'Database error') {
                throw $e;
            }
            throw new \RuntimeException('Database error');
        }
    }

    /**
     * Write a new ciphertext to a password record.
     *
     * @throws \RuntimeException On database error
     */
    public function writePassword(int $id, string $ciphertext): void
    {
        try {
            $stmt = $this->conn->prepare(
                'UPDATE settings SET smtp_password = ? WHERE id = ?'
            );
            if (!$stmt) {
                throw new \RuntimeException('Database error');
            }

            if (!$stmt->bind_param('si', $ciphertext, $id)) {
                $stmt->close();
                throw new \RuntimeException('Database error');
            }

            if (!$stmt->execute()) {
                $stmt->close();
                throw new \RuntimeException('Database error');
            }

            $stmt->close();

        } catch (\Throwable $e) {
            if ($e instanceof \RuntimeException && $e->getMessage() === 'Database error') {
                throw $e;
            }
            throw new \RuntimeException('Database error');
        }
    }

    /**
     * Read the current ciphertext from a password record.
     *
     * @throws \RuntimeException On database error
     */
    public function readPassword(int $id): ?string
    {
        try {
            $stmt = $this->conn->prepare(
                'SELECT smtp_password FROM settings WHERE id = ?'
            );
            if (!$stmt) {
                throw new \RuntimeException('Database error');
            }

            if (!$stmt->bind_param('i', $id)) {
                $stmt->close();
                throw new \RuntimeException('Database error');
            }

            if (!$stmt->execute()) {
                $stmt->close();
                throw new \RuntimeException('Database error');
            }

            $result = $stmt->get_result();
            if (!$result) {
                $stmt->close();
                throw new \RuntimeException('Database error');
            }

            $row = $result->fetch_assoc();
            $stmt->close();

            return $row ? $row['smtp_password'] : null;

        } catch (\Throwable $e) {
            if ($e instanceof \RuntimeException && $e->getMessage() === 'Database error') {
                throw $e;
            }
            throw new \RuntimeException('Database error');
        }
    }

    /**
     * Commit the active transaction.
     *
     * @throws \RuntimeException On database error
     */
    public function commit(): void
    {
        try {
            if (!$this->conn->commit()) {
                throw new \RuntimeException('Database error');
            }
        } catch (\Throwable $e) {
            if ($e instanceof \RuntimeException && $e->getMessage() === 'Database error') {
                throw $e;
            }
            throw new \RuntimeException('Database error');
        }
    }

    /**
     * Rollback the active transaction.
     *
     * @throws \RuntimeException On database error
     */
    public function rollback(): void
    {
        try {
            $this->conn->rollback();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Database error');
        }
    }
}

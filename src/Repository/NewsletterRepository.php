<?php

declare(strict_types=1);

namespace PostPortal\Repository;

/**
 * Newsletter repository implementation using prepared statements
 */
class NewsletterRepository extends BaseRepository implements NewsletterRepositoryInterface
{
    /**
     * Get active subscriber count
     *
     * @return int Number of active subscribers
     */
    public function getActiveSubscriberCount(): int
    {
        $sql = 'SELECT COUNT(*) as count FROM newsletter_subscribers WHERE is_active = 1';
        $result = $this->fetchColumn($sql);

        return $result !== null ? (int)$result : 0;
    }

    /**
     * Get total subscriber count
     *
     * @return int Total number of subscribers
     */
    public function getTotalSubscriberCount(): int
    {
        $sql = 'SELECT COUNT(*) as count FROM newsletter_subscribers';
        $result = $this->fetchColumn($sql);

        return $result !== null ? (int)$result : 0;
    }

    /**
     * Get all active subscriber emails
     *
     * @return array<int, string> Array of email addresses
     */
    public function getActiveSubscriberEmails(): array
    {
        $sql = 'SELECT email FROM newsletter_subscribers WHERE is_active = 1';
        $rows = $this->fetchAll($sql);

        return array_column($rows, 'email');
    }

    /**
     * Find subscriber by email
     *
     * @param string $email Email address
     * @return array<string, mixed>|null Subscriber data or null if not found
     */
    public function findByEmail(string $email): ?array
    {
        $sql = 'SELECT * FROM newsletter_subscribers WHERE email = ? LIMIT 1';
        return $this->fetchOne($sql, [$email]);
    }

    /**
     * Find subscriber by ID
     *
     * @param int $id Subscriber ID
     * @return array<string, mixed>|null Subscriber data or null if not found
     */
    public function findById(int $id): ?array
    {
        $sql = 'SELECT * FROM newsletter_subscribers WHERE id = ? LIMIT 1';
        return $this->fetchOne($sql, [$id]);
    }

    public function create(string $email, string $verificationTokenHash, ?string $ipAddress = null): int
    {
        $sql = 'INSERT INTO newsletter_subscribers (email, verification_token, verification_token_expires_at, ip_address, is_active, subscribed_at, created_at) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR), ?, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())';
        $this->execute($sql, [$email, $verificationTokenHash, $ipAddress]);

        return $this->lastInsertId();
    }

    /**
     * Verify a subscriber using a hashed token.
     * Token must exist, not be expired, and subscriber must be inactive.
     * This is atomic: only one subscriber can win the race to verify with this token.
     *
     * @param string $tokenHash Hashed verification token
     * @param int $maxAgeSeconds Maximum age of token in seconds (unused, for interface compatibility)
     * @return bool True on success (exactly one row updated)
     */
    public function verify(string $tokenHash, int $maxAgeSeconds = 86400): bool
    {
        $sql = 'UPDATE newsletter_subscribers
                SET is_active = 1, verified_at = UTC_TIMESTAMP(), verification_token = NULL, verification_token_expires_at = NULL, updated_at = UTC_TIMESTAMP()
                WHERE verification_token = ?
                  AND verification_token_expires_at > UTC_TIMESTAMP()
                  AND is_active = 0
                LIMIT 1';
        $this->execute($sql, [$tokenHash]);

        return $this->affectedRows() > 0;
    }

    /**
     * Unsubscribe a subscriber
     *
     * @param string $email Email address
     * @return bool True on success
     */
    public function unsubscribe(string $email): bool
    {
        $sql = 'UPDATE newsletter_subscribers SET is_active = 0, unsubscribed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE email = ?';
        $this->execute($sql, [$email]);

        return $this->affectedRows() > 0;
    }

    /**
     * Get subscribers with optional active filter.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSubscribers(bool $activeOnly = true): array
    {
        $sql = 'SELECT id, email, subscribed_at, ip_address, is_active, created_at, updated_at FROM newsletter_subscribers';
        $params = [];

        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        } else {
            $sql .= ' WHERE is_active = 0';
        }

        $sql .= ' ORDER BY subscribed_at DESC';

        return $this->fetchAll($sql, $params);
    }

    public function archive(int $id): bool
    {
        $sql = 'UPDATE newsletter_subscribers SET is_active = 0, updated_at = UTC_TIMESTAMP() WHERE id = ?';
        $this->execute($sql, [$id]);

        return $this->affectedRows() > 0;
    }

    public function setPendingVerification(int $id, string $verificationTokenHash): bool
    {
        $sql = 'UPDATE newsletter_subscribers
                SET is_active = 0, verification_token = ?, verification_token_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR), verified_at = NULL, updated_at = UTC_TIMESTAMP()
                WHERE id = ?';
        $this->execute($sql, [$verificationTokenHash, $id]);

        return $this->affectedRows() > 0;
    }

    public function beginNewsletterTransaction(): void
    {
        if (!$this->beginTransaction()) {
            throw new \RuntimeException('Could not start newsletter transaction');
        }
    }

    public function commitNewsletterTransaction(): void
    {
        if (!$this->commit()) {
            throw new \RuntimeException('Could not commit newsletter transaction');
        }
    }

    public function rollbackNewsletterTransaction(): void
    {
        $this->rollback();
    }
}

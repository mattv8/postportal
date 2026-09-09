<?php

declare(strict_types=1);

namespace PostPortal\Repository;

final class NewsletterThrottleRepository extends BaseRepository implements NewsletterThrottleRepositoryInterface
{
    /**
     * Check if source is throttled and update attempt count
     * Returns true if throttled (request should be rejected with 429)
     *
     * @param string $sourceKey HMAC-derived source identifier
     * @param int $maxAttemptsPerWindow Max requests allowed per window
     * @param int $windowSeconds Window duration in seconds (default 3600 = 1 hour)
     * @return bool True if request is throttled, false if allowed
     */
    public function isThrottled(string $sourceKey, int $maxAttemptsPerWindow = 5, int $windowSeconds = 3600): bool
    {
        $limit = max(1, min($maxAttemptsPerWindow, 1000));
        $window = max(1, min($windowSeconds, 86400));
        $started = false;

        try {
            if (!$this->beginTransaction()) {
                return true;
            }
            $started = true;

            // Establish the unique row before locking it so concurrent first requests
            // serialize on the source_key unique index rather than racing an INSERT.
            $this->execute(
                'INSERT IGNORE INTO newsletter_throttle (source_key, request_count, window_start) VALUES (?, 1, UTC_TIMESTAMP())',
                [$sourceKey]
            );
            if ($this->affectedRows() === 1) {
                if (!$this->commit()) {
                    throw new \RuntimeException('Could not commit newsletter throttle transaction');
                }
                $started = false;
                return false;
            }

            // A locking read serializes all updates for an existing source row.
            $row = $this->fetchOne(
                'SELECT id, request_count, window_start FROM newsletter_throttle WHERE source_key = ? LIMIT 1 FOR UPDATE',
                [$sourceKey]
            );

            if ($row === null) {
                throw new \RuntimeException('Newsletter throttle row was not available after insert');
            }

            $windowStart = strtotime((string) $row['window_start'] . ' UTC');
            if ($windowStart === false || $windowStart < time() - $window) {
                $this->execute(
                    'UPDATE newsletter_throttle SET request_count = 1, window_start = UTC_TIMESTAMP() WHERE id = ?',
                    [(int) $row['id']]
                );
                if (!$this->commit()) {
                    throw new \RuntimeException('Could not commit newsletter throttle transaction');
                }
                $started = false;
                return false;
            }

            $count = max(1, min((int) $row['request_count'], $limit + 1));
            $throttled = $count >= $limit;
            $this->execute(
                'UPDATE newsletter_throttle SET request_count = ? WHERE id = ?',
                [$throttled ? $limit + 1 : $count + 1, (int) $row['id']]
            );
            if (!$this->commit()) {
                throw new \RuntimeException('Could not commit newsletter throttle transaction');
            }
            $started = false;
            return $throttled;
        } catch (\Throwable) {
            if ($started) {
                $this->rollback();
            }
            return true;
        }
    }

    /**
     * Clean up expired throttle records (older than 7 days)
     * Call periodically to prevent table bloat
     */
    public function cleanupExpiredRecords(): int
    {
        $sql = 'DELETE FROM newsletter_throttle WHERE window_start < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)';
        $this->execute($sql, []);
        return $this->affectedRows();
    }

    /**
     * Derive a source key from IP address with HMAC for privacy
     * Uses a fixed app secret so the key is stable across deployments
     * but doesn't leak raw IPs in the database
     *
     * @param string $ipAddress Client IP address
     * @return string HMAC-derived source key (64 hex chars)
     */
    public static function deriveSourceKey(string $ipAddress): string
    {
        $keyHex = getenv('APP_ENCRYPTION_KEY');
        if (!is_string($keyHex) || !preg_match('/\A[0-9a-fA-F]{64}\z/', $keyHex)) {
            throw new \RuntimeException('Newsletter throttling key is not configured');
        }

        $key = hex2bin($keyHex);
        if ($key === false) {
            throw new \RuntimeException('Newsletter throttling key is invalid');
        }

        return hash_hmac('sha256', "newsletter-throttle-source\0" . $ipAddress, $key);
    }
}

<?php

declare(strict_types=1);

namespace PostPortal\Repository;

use PostPortal\Security\HmacPrivacyKey;

/**
 * Repository for user database operations
 */
class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    /**
     * Allowed fields for user updates (security whitelist)
     */
    private const ALLOWED_FIELDS = [
        'username',
        'password',
        'first',
        'last',
        'email',
        'isadmin',
        'active',
        'siteMemberships',
    ];

    /**
     * @inheritDoc
     */
    public function getAll(): array
    {
        $sql = 'SELECT username, first, last, email, isadmin, active, siteMemberships
                FROM users
                ORDER BY username ASC';
        return $this->fetchAll($sql);
    }

    /**
     * @inheritDoc
     */
    public function getByUsername(string $username): ?array
    {
        $sql = 'SELECT username, first, last, email, isadmin, active, siteMemberships
                FROM users
                WHERE username = ?';
        return $this->fetchOne($sql, [$username]);
    }

    /**
     * @inheritDoc
     */
    public function create(array $data): bool
    {
        // Filter to only allowed fields
        $filteredData = array_intersect_key($data, array_flip(self::ALLOWED_FIELDS));

        if (empty($filteredData['username'])) {
            return false;
        }

        // Hash password if provided
        if (!empty($filteredData['password'])) {
            $filteredData['password'] = password_hash($filteredData['password'], PASSWORD_DEFAULT);
        }

        // Set defaults
        $filteredData['isadmin'] = (int)($filteredData['isadmin'] ?? 0);
        $filteredData['active'] = (int)($filteredData['active'] ?? 1);
        $filteredData['siteMemberships'] = $filteredData['siteMemberships'] ?? '[]';

        $columns = implode(', ', array_keys($filteredData));
        $placeholders = implode(', ', array_fill(0, count($filteredData), '?'));

        $sql = "INSERT INTO users ($columns) VALUES ($placeholders)";
        $result = $this->execute($sql, array_values($filteredData));

        return $result === true;
    }

    /**
     * @inheritDoc
     */
    public function updateAttribute(string $username, string $field, mixed $value): bool
    {
        if (!in_array($field, self::ALLOWED_FIELDS, true)) {
            return false;
        }

        // Hash password if updating password
        if ($field === 'password' && is_string($value) && $value !== '') {
            $value = password_hash($value, PASSWORD_DEFAULT);
        }

        // Convert booleans to int for database
        if ($field === 'isadmin' || $field === 'active') {
            $value = (int)$value;
        }

        $sql = "UPDATE users SET $field = ? WHERE username = ?";
        $result = $this->execute($sql, [$value, $username]);

        // Return true if query executed successfully (even if no rows changed)
        return $result === true;
    }

    /**
     * @inheritDoc
     */
    public function update(string $username, array $data): bool
    {
        // Filter to only allowed fields
        $filteredData = array_intersect_key($data, array_flip(self::ALLOWED_FIELDS));

        if (empty($filteredData)) {
            return false;
        }

        // Hash password if provided and not empty
        if (isset($filteredData['password'])) {
            if ($filteredData['password'] === '') {
                unset($filteredData['password']); // Don't update password if empty
            } else {
                $filteredData['password'] = password_hash($filteredData['password'], PASSWORD_DEFAULT);
            }
        }

        // Convert booleans to int for database
        if (isset($filteredData['isadmin'])) {
            $filteredData['isadmin'] = (int)$filteredData['isadmin'];
        }
        if (isset($filteredData['active'])) {
            $filteredData['active'] = (int)$filteredData['active'];
        }

        if (empty($filteredData)) {
            return true; // Nothing to update
        }

        $setClauses = [];
        $params = [];
        foreach ($filteredData as $field => $value) {
            $setClauses[] = "$field = ?";
            $params[] = $value;
        }
        $params[] = $username;

        $sql = 'UPDATE users SET ' . implode(', ', $setClauses) . ' WHERE username = ?';
        $result = $this->execute($sql, $params);

        // Return true if query executed successfully (even if no rows changed)
        return $result === true;
    }

    /**
     * @inheritDoc
     */
    public function delete(string $username): bool
    {
        $sql = 'DELETE FROM users WHERE username = ?';
        $result = $this->execute($sql, [$username]);

        return $result === true && $this->affectedRows() > 0;
    }

    /**
     * @inheritDoc
     */
    public function exists(string $username): bool
    {
        $sql = 'SELECT 1 FROM users WHERE username = ? LIMIT 1';
        return $this->fetchOne($sql, [$username]) !== null;
    }

    /**
     * @inheritDoc
     * SEC-11: Issue a one-time, hashed, purpose-bound activation token.
     */
    public function issueActivationToken(string $username): array
    {
        try {
            // Verify user exists
            $user = $this->getByUsername($username);
            if ($user === null) {
                return [
                    'success' => false,
                    'error' => 'User not found',
                ];
            }

            // Generate raw token (returned to caller, never stored)
            $rawToken = bin2hex(random_bytes(32));

            // Store only the hash + purpose + expiry
            $tokenHash = hash('sha256', $rawToken, true);
            $expiresAt = gmdate('Y-m-d H:i:s', time() + 86400); // 24 hours
            $purpose = 'activate';

            $sql = 'INSERT INTO activation_tokens (username, token_hash, purpose, expires_at)
                    VALUES (?, ?, ?, ?)';
            $result = $this->execute($sql, [$username, $tokenHash, $purpose, $expiresAt]);

            if ($result !== true) {
                return [
                    'success' => false,
                    'error' => 'Failed to create activation token',
                ];
            }

            return [
                'success' => true,
                'token' => $rawToken,
                'expires_at' => $expiresAt,
            ];
        } catch (\Throwable $e) {
            error_log('UserRepository::issueActivationToken error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to issue activation token',
            ];
        }
    }

    /**
     * @inheritDoc
     * SEC-11: Redeem a token atomically with database transaction.
     * Ensures exactly one concurrent call wins (first one); others fail.
     * Uses guarded UPDATE with affected-row check to detect concurrent redemption.
     */
    public function redeemActivationToken(string $username, string $rawToken, string $newPassword): array
    {
        try {
            // Hash the provided token for comparison
            $tokenHash = hash('sha256', $rawToken, true);

            // Verify user exists
            if (!$this->exists($username)) {
                return [
                    'success' => false,
                    'error' => 'Invalid or already consumed activation token',
                ];
            }

            // Find a valid activation token for this user (read-only check)
            $sql = 'SELECT id, expires_at FROM activation_tokens
                    WHERE username = ? AND token_hash = ? AND purpose = ?
                      AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP()
                    LIMIT 1';
            $token = $this->fetchOne($sql, [$username, $tokenHash, 'activate']);

            if ($token === null) {
                return [
                    'success' => false,
                    'error' => 'Invalid or already consumed activation token',
                ];
            }

            // Start transaction for atomic updates
            $this->beginTransaction();

            try {
                // Hash the new password
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

                // Mark token as consumed only while it remains valid.
                // This UPDATE will succeed only if no concurrent call already marked it consumed
                $tokenSql = 'UPDATE activation_tokens SET consumed_at = NOW()
                             WHERE id = ? AND purpose = ?
                               AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP()';
                $this->execute($tokenSql, [$token['id'], 'activate']);

                // Check if exactly one row was affected
                // If 0 rows affected: concurrent call won, rollback and fail
                // If 1 row affected: we won, proceed
                $tokenRows = $this->affectedRows();
                if ($tokenRows !== 1) {
                    // Concurrent redemption: another call already consumed this token
                    $this->rollback();
                    return [
                        'success' => false,
                        'error' => 'Invalid or already consumed activation token',
                    ];
                }

                // Atomically update password and activate user
                $userSql = 'UPDATE users SET password = ?, active = 1 WHERE username = ?';
                $this->execute($userSql, [$passwordHash, $username]);

                // Verify user row was updated
                $userRows = $this->affectedRows();
                if ($userRows !== 1) {
                    // User update failed, rollback token consumption
                    $this->rollback();
                    return [
                        'success' => false,
                        'error' => 'Invalid or already consumed activation token',
                    ];
                }

                // Commit transaction
                $this->commit();
                return ['success' => true];
            } catch (\Throwable $e) {
                // Rollback on any error during transaction
                $this->rollback();
                throw $e;
            }
        } catch (\Throwable $e) {
            error_log('UserRepository::redeemActivationToken error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Invalid or already consumed activation token',
            ];
        }
    }

    /**
     * @inheritDoc
     * SEC-10: Check if login attempt threshold is exceeded.
     * Two independent scopes: source IP and normalized username.
     * Either exceeding threshold blocks login with exponential Retry-After.
     */
    public function checkLoginThrottle(string $sourceKey, string $usernameKey): array
    {
        try {
            // Derive HMAC keys (cannot be reversed)
            $sourceHmac = HmacPrivacyKey::generateThrottleKey($sourceKey);
            $usernameHmac = HmacPrivacyKey::generateThrottleKey($usernameKey);

            // Probabilistic cleanup: 5% chance each per scope
            if (mt_rand(0, 99) < 5) {
                $this->execute('DELETE FROM login_attempts_by_source WHERE expires_at < UTC_TIMESTAMP()', []);
            }
            if (mt_rand(0, 99) < 5) {
                $this->execute('DELETE FROM login_attempts_by_username WHERE expires_at < UTC_TIMESTAMP()', []);
            }

            // Check source scope (one IP rotating many usernames)
            $sourceRecord = $this->fetchOne(
                'SELECT attempt_count, expires_at FROM login_attempts_by_source WHERE source_hmac = ? LIMIT 1',
                [$sourceHmac]
            );

            // Check username scope (one username from many IPs)
            $usernameRecord = $this->fetchOne(
                'SELECT attempt_count, expires_at FROM login_attempts_by_username WHERE username_hmac = ? LIMIT 1',
                [$usernameHmac]
            );

            $now = time();
            $sourceThrottled = false;
            $usernameThrottled = false;
            $maxRetryAfter = 0;

            // Evaluate source scope
            if ($sourceRecord !== null) {
                $attempts = (int)$sourceRecord['attempt_count'];
                $expiresAt = $this->utcTimestamp($sourceRecord['expires_at']);
                if ($attempts >= 5 && $expiresAt > $now) {
                    $sourceThrottled = true;
                    $secondsRemaining = max(1, $expiresAt - $now);
                    // Exponential: 5 attempts = 1x, 6 = 2x, 7+ = 4x, capped by actual expiry
                    $exponentialMultiplier = min(4, pow(2, max(0, $attempts - 5)));
                    $retryAfter = (int)($secondsRemaining * $exponentialMultiplier);
                    $maxRetryAfter = max($maxRetryAfter, $retryAfter);
                }
            }

            // Evaluate username scope
            if ($usernameRecord !== null) {
                $attempts = (int)$usernameRecord['attempt_count'];
                $expiresAt = $this->utcTimestamp($usernameRecord['expires_at']);
                if ($attempts >= 5 && $expiresAt > $now) {
                    $usernameThrottled = true;
                    $secondsRemaining = max(1, $expiresAt - $now);
                    $exponentialMultiplier = min(4, pow(2, max(0, $attempts - 5)));
                    $retryAfter = (int)($secondsRemaining * $exponentialMultiplier);
                    $maxRetryAfter = max($maxRetryAfter, $retryAfter);
                }
            }

            // Return generic response: either scope throttled = blocked
            if ($sourceThrottled || $usernameThrottled) {
                return [
                    'allowed' => false,
                    'retry_after' => $maxRetryAfter,
                ];
            }

            return ['allowed' => true];
        } catch (\Throwable $e) {
            error_log('UserRepository::checkLoginThrottle error: ' . $e->getMessage());
            return ['allowed' => false, 'retry_after' => 60];
        }
    }

    /**
     * @inheritDoc
     * SEC-10: Record failed login attempt in both scopes independently.
     */
    public function recordLoginAttempt(string $sourceKey, string $usernameKey): bool
    {
        try {
            $sourceHmac = HmacPrivacyKey::generateThrottleKey($sourceKey);
            $usernameHmac = HmacPrivacyKey::generateThrottleKey($usernameKey);
            // Record in source scope (rolling window)
            $this->execute(
                'INSERT INTO login_attempts_by_source (source_hmac, attempt_count, last_attempt_at, expires_at)
                 VALUES (?, 1, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE))
                 ON DUPLICATE KEY UPDATE
                   attempt_count = IF(expires_at <= UTC_TIMESTAMP(), 1, attempt_count + 1),
                   last_attempt_at = UTC_TIMESTAMP(),
                   expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)',
                [$sourceHmac]
            );

            // Record in username scope (rolling window)
            $this->execute(
                'INSERT INTO login_attempts_by_username (username_hmac, attempt_count, last_attempt_at, expires_at)
                 VALUES (?, 1, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE))
                 ON DUPLICATE KEY UPDATE
                   attempt_count = IF(expires_at <= UTC_TIMESTAMP(), 1, attempt_count + 1),
                   last_attempt_at = UTC_TIMESTAMP(),
                   expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)',
                [$usernameHmac]
            );

            return true;
        } catch (\Throwable $e) {
            error_log('UserRepository::recordLoginAttempt error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @inheritDoc
     * SEC-10: Clear throttle records after successful login (decay).
     */
    public function clearLoginAttempts(string $sourceKey, string $usernameKey): bool
    {
        try {
            $sourceHmac = HmacPrivacyKey::generateThrottleKey($sourceKey);
            $usernameHmac = HmacPrivacyKey::generateThrottleKey($usernameKey);

            // Clear both scopes
            $this->execute('DELETE FROM login_attempts_by_source WHERE source_hmac = ?', [$sourceHmac]);
            $this->execute('DELETE FROM login_attempts_by_username WHERE username_hmac = ?', [$usernameHmac]);

            return true;
        } catch (\Throwable $e) {
            error_log('UserRepository::clearLoginAttempts error: ' . $e->getMessage());
            return false;
        }
    }

    private function utcTimestamp(string $timestamp): int
    {
        return (new \DateTimeImmutable($timestamp, new \DateTimeZone('UTC')))->getTimestamp();
    }
}

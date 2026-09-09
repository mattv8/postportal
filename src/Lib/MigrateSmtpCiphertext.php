<?php

declare(strict_types=1);

namespace PostPortal\Lib;

use Throwable;

/**
 * Migrate legacy AES-CBC SMTP passwords to v2 AES-256-GCM.
 *
 * This CLI tool:
 * - Identifies v2 envelopes (already migrated)
 * - Decrypts legacy AES-CBC using database password derivation
 * - Re-encrypts with v2 using independent APP_ENCRYPTION_KEY
 * - Uses transaction/lock/verify/commit for recoverability
 * - Never prints plaintext or keys
 *
 * Exit codes:
 * 0 = Success, already-v2, or nothing-to-do
 * 1 = Configuration missing
 * 2 = Database/crypto failure
 * 3 = Partial failure (some migrated, some failed)
 */
class MigrateSmtpCiphertext
{
    private SmtpPasswordPersistence $persistence;
    private string $appKey;
    private string $dbPassword;
    private string $dbName;

    /**
     * @param SmtpPasswordPersistence $persistence
     * @param string|null $appKeyHex APP_ENCRYPTION_KEY (hex); if null, reads from env
     * @param string|null $dbPassword Database password; if null, reads from env
     * @param string|null $dbName Database name; if null, reads from env
     */
    public function __construct(
        SmtpPasswordPersistence $persistence,
        ?string $appKeyHex = null,
        ?string $dbPassword = null,
        ?string $dbName = null
    ) {
        $this->persistence = $persistence;

        // Validate configuration
        $appKeyHex ??= getenv('APP_ENCRYPTION_KEY') ?: '';
        if ($appKeyHex === '' || !ctype_xdigit($appKeyHex) || strlen($appKeyHex) !== 64) {
            throw new \RuntimeException('Invalid APP_ENCRYPTION_KEY');
        }
        $this->appKey = hex2bin($appKeyHex);

        if ($dbPassword === null || $dbName === null) {
            $cfg = $this->getDbConfig();
            $dbPassword ??= $cfg['password'];
            $dbName ??= $cfg['database'];
        }

        $this->dbPassword = $dbPassword ?? '';
        $this->dbName = $dbName ?? '';

        if ($this->dbPassword === '' || $this->dbName === '') {
            throw new \RuntimeException('Invalid database configuration');
        }
    }

    /**
     * Run the migration.
     *
     * @return int Exit code (0=success/no-op, 1=config, 2=db/crypto, 3=partial)
     */
    public function run(): int
    {
        try {
            // Fetch records
            try {
                $records = $this->persistence->fetchAllPasswords();
            } catch (Throwable $e) {
                error_log('MigrateSmtpCiphertext: Database fetch failed');
                return 2;
            }

            if (empty($records)) {
                // Nothing to do
                return 0;
            }

            $migrated = 0;
            $failed = 0;

            foreach ($records as $row) {
                $id = (int)$row['id'];
                $encrypted = $row['smtp_password'];

                if (!$this->migrateRecord($id, $encrypted)) {
                    $failed++;
                } else {
                    $migrated++;
                }
            }

            // Return appropriate exit code
            if ($failed === 0) {
                return 0;  // All success or already-v2
            } elseif ($migrated === 0) {
                return 2;  // All failed
            } else {
                return 3;  // Partial failure
            }

        } catch (Throwable $e) {
            // Only config errors should reach here
            error_log('MigrateSmtpCiphertext: Configuration error');
            return 1;
        }
    }

    /**
     * Migrate a single record.
     *
     * Algorithm:
     * 1. Strict-base64 decode. Valid authenticated v2 is a no-op; malformed/invalid v2 fails.
     * 2. Privately decrypt legacy AES-256-CBC with database-password/database-name derivation.
     * 3. Encrypt v2 with the already accepted validated 32-byte app key.
     * 4. Begin transaction and mark it active.
     * 5. Lock/read row; require exact equality with the originally fetched ciphertext.
     * 6. Write v2; read it back; require exact ciphertext equality.
     * 7. Decrypt readback v2; exact-compare with original legacy plaintext.
     * 8. Commit and clear active transaction.
     * 9. On every exception/mismatch after begin, call rollback. Swallow rollback failure after a generic log.
     */
    private function migrateRecord(int $id, string $encrypted): bool
    {
        try {
            // Step 1: Decode envelope
            $envelope = base64_decode($encrypted, true);
            if ($envelope === false || strlen($envelope) < 1) {
                error_log("MigrateSmtpCiphertext: Record $id: Decode failed");
                return false;
            }

            // Step 2: Check if already v2
            $version = ord($envelope[0]);
            if ($version === 2) {
                // Already migrated, verify it decrypts (no-op, no transaction)
                if ($this->verifyV2Envelope($envelope)) {
                    return true;  // Already v2 and valid - no-op
                } else {
                    error_log("MigrateSmtpCiphertext: Record $id: V2 verification failed");
                    return false;
                }
            }

            // Step 3: Decrypt legacy AES-CBC (version 1 or unversioned)
            $plaintext = $this->decryptLegacy($envelope);
            if ($plaintext === null) {
                error_log("MigrateSmtpCiphertext: Record $id: Decrypt legacy failed");
                return false;
            }

            // Step 4: Encrypt to v2
            $v2Encrypted = $this->encryptToV2($plaintext);
            if ($v2Encrypted === null) {
                error_log("MigrateSmtpCiphertext: Record $id: Encrypt v2 failed");
                return false;
            }

            // Step 5: Begin transaction
            try {
                $this->persistence->beginTransaction();
            } catch (Throwable $e) {
                error_log("MigrateSmtpCiphertext: Record $id: Begin transaction failed");
                return false;
            }

            // Step 6: Lock and verify original unchanged
            try {
                $locked = $this->persistence->lockPassword($id);
                if ($locked === null || $locked !== $encrypted) {
                    error_log("MigrateSmtpCiphertext: Record $id: Locked record does not match original");
                    $this->rollbackTransaction();
                    return false;
                }
            } catch (Throwable $e) {
                error_log("MigrateSmtpCiphertext: Record $id: Lock failed");
                $this->rollbackTransaction();
                return false;
            }

            // Step 7: Write v2
            try {
                $this->persistence->writePassword($id, $v2Encrypted);
            } catch (Throwable $e) {
                error_log("MigrateSmtpCiphertext: Record $id: Write failed");
                $this->rollbackTransaction();
                return false;
            }

            // Step 8: Read back and decrypt readback v2
            try {
                $readback = $this->persistence->readPassword($id);
                if ($readback === null) {
                    error_log("MigrateSmtpCiphertext: Record $id: Readback is null");
                    $this->rollbackTransaction();
                    return false;
                }
            } catch (Throwable $e) {
                error_log("MigrateSmtpCiphertext: Record $id: Read failed");
                $this->rollbackTransaction();
                return false;
            }

            // Step 9: Decrypt readback v2 and exact-compare plaintext with original
            try {
                $decryptedReadback = $this->verifyAndDecryptV2($readback);
                if ($decryptedReadback === false || $decryptedReadback !== $plaintext) {
                    error_log("MigrateSmtpCiphertext: Record $id: V2 decrypt/plaintext mismatch");
                    $this->rollbackTransaction();
                    return false;
                }
            } catch (Throwable $e) {
                error_log("MigrateSmtpCiphertext: Record $id: V2 decrypt failed");
                $this->rollbackTransaction();
                return false;
            }

            // Step 10: Verify exact ciphertext equality with written v2
            if ($readback !== $v2Encrypted) {
                error_log("MigrateSmtpCiphertext: Record $id: Ciphertext identity mismatch");
                $this->rollbackTransaction();
                return false;
            }

            // Step 11: Commit
            try {
                $this->persistence->commit();
            } catch (Throwable $e) {
                error_log("MigrateSmtpCiphertext: Record $id: Commit failed");
                $this->rollbackTransaction();
                return false;
            }

            return true;

        } catch (Throwable $e) {
            error_log("MigrateSmtpCiphertext: Record $id: Unexpected error");
            return false;
        }
    }

    /**
     * Rollback transaction. Swallow rollback failure and log generically.
     */
    private function rollbackTransaction(): void
    {
        try {
            $this->persistence->rollback();
        } catch (Throwable $e) {
            error_log('MigrateSmtpCiphertext: Rollback failed');
        }
    }

    /**
     * Decrypt legacy AES-CBC ciphertext.
     * Returns null on failure (never logs plaintext).
     */
    private function decryptLegacy(string $envelope): ?string
    {
        try {
            // Legacy format: IV (16 bytes) + ciphertext
            // Or: just ciphertext if old format
            $key = hash('sha256', $this->dbPassword . $this->dbName, true);
            $ivLength = openssl_cipher_iv_length('aes-256-cbc');

            if (strlen($envelope) < $ivLength + 1) {
                return null;
            }

            $iv = substr($envelope, 0, $ivLength);
            $ciphertext = substr($envelope, $ivLength);

            $plaintext = openssl_decrypt(
                $ciphertext,
                'aes-256-cbc',
                $key,
                0,
                $iv
            );

            if ($plaintext === false) {
                return null;
            }

            return $plaintext;

        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Encrypt plaintext to v2 AES-256-GCM.
     * Returns base64-encoded envelope or null on failure.
     */
    private function encryptToV2(string $plaintext): ?string
    {
        try {
            $nonce = random_bytes(12);

            $tag = '';
            $encrypted = openssl_encrypt(
                $plaintext,
                'aes-256-gcm',
                $this->appKey,
                OPENSSL_RAW_DATA,
                $nonce,
                $tag
            );

            if ($encrypted === false) {
                return null;
            }

            // v2 envelope: version (1) + nonce (12) + ciphertext + tag (16)
            $envelope = chr(2) . $nonce . $encrypted . $tag;
            return base64_encode($envelope);

        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Verify v2 envelope structure and decrypt (without logging plaintext).
     */
    public function verifyV2Envelope(string $envelope): bool
    {
        try {
            if (strlen($envelope) < 29) {  // min: 1 + 12 + 0 + 16
                return false;
            }

            $version = ord($envelope[0]);
            if ($version !== 2) {
                return false;
            }

            $nonce = substr($envelope, 1, 12);
            $tag = substr($envelope, -16);
            $ciphertext = substr($envelope, 13, -16);

            if (strlen($nonce) !== 12 || strlen($tag) !== 16) {
                return false;
            }

            $decrypted = openssl_decrypt(
                $ciphertext,
                'aes-256-gcm',
                $this->appKey,
                OPENSSL_RAW_DATA,
                $nonce,
                $tag
            );

            return $decrypted !== false;

        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Verify and decrypt v2 ciphertext, returning plaintext.
     * Used by persistence boundary for exact-compare verification.
     *
     * @param string $v2Encrypted Base64-encoded v2 envelope
     * @return string|false Plaintext if valid, false on any error
     */
    public function verifyAndDecryptV2(string $v2Encrypted): string|false
    {
        try {
            $envelope = base64_decode($v2Encrypted, true);
            if ($envelope === false || strlen($envelope) < 29) {
                return false;
            }

            $version = ord($envelope[0]);
            if ($version !== 2) {
                return false;
            }

            $nonce = substr($envelope, 1, 12);
            $tag = substr($envelope, -16);
            $ciphertext = substr($envelope, 13, -16);

            if (strlen($nonce) !== 12 || strlen($tag) !== 16) {
                return false;
            }

            $decrypted = openssl_decrypt(
                $ciphertext,
                'aes-256-gcm',
                $this->appKey,
                OPENSSL_RAW_DATA,
                $nonce,
                $tag
            );

            return $decrypted;

        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Get database configuration from environment.
     */
    private function getDbConfig(): array
    {
        return [
            'host' => getenv('MYSQL_HOST') ?: '',
            'database' => getenv('MYSQL_DATABASE') ?: '',
            'user' => getenv('MYSQL_USER') ?: '',
            'password' => getenv('MYSQL_PASSWORD') ?: '',
        ];
    }
}

<?php

declare(strict_types=1);

namespace PostPortal\Lib;

use PostPortal\Security\AppKeyDeriver;
use RuntimeException;
use Throwable;

/** Migrates legacy and v2 SMTP ciphertext to derived-key v3 envelopes. */
class MigrateSmtpCiphertext
{
    private SmtpPasswordPersistence $persistence;
    /** Legacy v2 key: decoded APP_SECRET root bytes. */
    private string $appSecretRootKey;
    private string $smtpKey;
    private string $dbPassword;
    private string $dbName;

    public function __construct(
        SmtpPasswordPersistence $persistence,
        ?string $appSecretHex = null,
        ?string $dbPassword = null,
        ?string $dbName = null
    ) {
        $this->persistence = $persistence;
        $appSecretHex ??= getenv('APP_SECRET') ?: '';
        if (!ctype_xdigit($appSecretHex) || strlen($appSecretHex) !== 64) {
            throw new RuntimeException('Invalid application secret configuration');
        }

        $rootKey = hex2bin($appSecretHex);
        if ($rootKey === false || strlen($rootKey) !== 32) {
            throw new RuntimeException('Invalid application secret configuration');
        }
        $this->appSecretRootKey = $rootKey;
        $this->smtpKey = AppKeyDeriver::smtpEncryptionKey($appSecretHex);

        if ($dbPassword === null || $dbName === null) {
            $dbPassword ??= getenv('MYSQL_PASSWORD') ?: '';
            $dbName ??= getenv('MYSQL_DATABASE') ?: '';
        }
        $this->dbPassword = $dbPassword;
        $this->dbName = $dbName;
        if ($this->dbPassword === '' || $this->dbName === '') {
            throw new RuntimeException('Invalid database configuration');
        }
    }

    public function run(): int
    {
        try {
            $records = $this->persistence->fetchAllPasswords();
        } catch (Throwable $e) {
            error_log('MigrateSmtpCiphertext: Database fetch failed');
            return 2;
        }

        $migrated = 0;
        $failed = 0;
        foreach ($records as $row) {
            if ($this->migrateRecord((int) $row['id'], (string) $row['smtp_password'])) {
                $migrated++;
            } else {
                $failed++;
            }
        }

        return $failed === 0 ? 0 : ($migrated === 0 ? 2 : 3);
    }

    private function migrateRecord(int $id, string $original): bool
    {
        $transactionStarted = false;
        try {
            $envelope = base64_decode($original, true);
            if ($envelope === false || $envelope === '') {
                return false;
            }

            $version = ord($envelope[0]);
            if ($version === 3) {
                if ($this->verifyV3Envelope($envelope)) {
                    return true;
                }
                $plaintext = $this->decryptLegacy($envelope);
            } elseif ($version === 2) {
                $plaintext = $this->decryptEnvelope($envelope, 2, $this->appSecretRootKey);
                if ($plaintext === false) {
                    $plaintext = $this->decryptLegacy($envelope);
                }
            } else {
                $plaintext = $this->decryptLegacy($envelope);
            }
            if ($plaintext === null) {
                return false;
            }

            $v3Encrypted = $this->encryptToV3($plaintext);
            if ($v3Encrypted === false) {
                return false;
            }

            $this->persistence->beginTransaction();
            $transactionStarted = true;
            if ($this->persistence->lockPassword($id) !== $original) {
                throw new RuntimeException('Locked record does not match original');
            }
            $this->persistence->writePassword($id, $v3Encrypted);
            $readback = $this->persistence->readPassword($id);
            if ($readback === null) {
                throw new RuntimeException('Readback is null');
            }
            $decryptedReadback = $this->verifyAndDecryptV3($readback);
            if ($decryptedReadback === false || $decryptedReadback !== $plaintext || $readback !== $v3Encrypted) {
                throw new RuntimeException('Readback verification failed');
            }
            $this->persistence->commit();
            return true;
        } catch (Throwable $e) {
            if ($transactionStarted) {
                $this->rollbackTransaction();
            }
            error_log("MigrateSmtpCiphertext: Record $id: Migration failed");
            return false;
        }
    }

    private function rollbackTransaction(): void
    {
        try {
            $this->persistence->rollback();
        } catch (Throwable $e) {
            error_log('MigrateSmtpCiphertext: Rollback failed');
        }
    }

    private function decryptLegacy(string $envelope): ?string
    {
        try {
            $ivLength = openssl_cipher_iv_length('aes-256-cbc');
            if ($ivLength === false || strlen($envelope) < $ivLength + 1) {
                return null;
            }
            $ciphertext = substr($envelope, $ivLength);
            $decodedCiphertext = base64_decode($ciphertext, true);
            if ($decodedCiphertext === false || $decodedCiphertext === '' ||
                base64_encode($decodedCiphertext) !== $ciphertext ||
                strlen($decodedCiphertext) % $ivLength !== 0) {
                return null;
            }
            $plaintext = openssl_decrypt(
                $ciphertext,
                'aes-256-cbc',
                hash('sha256', $this->dbPassword . $this->dbName, true),
                0,
                substr($envelope, 0, $ivLength)
            );
            return $plaintext === false ? null : $plaintext;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function encryptToV3(string $plaintext): string|false
    {
        try {
            $nonce = random_bytes(12);
            $tag = '';
            $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->smtpKey, OPENSSL_RAW_DATA, $nonce, $tag);
            return $ciphertext === false ? false : base64_encode(chr(3) . $nonce . $ciphertext . $tag);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function verifyV3Envelope(string $envelope): bool
    {
        return $this->decryptEnvelope($envelope, 3, $this->smtpKey) !== false;
    }

    public function verifyAndDecryptV3(string $v3Encrypted): string|false
    {
        try {
            $envelope = base64_decode($v3Encrypted, true);
            return $envelope === false ? false : $this->decryptEnvelope($envelope, 3, $this->smtpKey);
        } catch (Throwable $e) {
            return false;
        }
    }

    private function decryptEnvelope(string $envelope, int $version, string $key): string|false
    {
        try {
            if (strlen($envelope) < 29 || ord($envelope[0]) !== $version) {
                return false;
            }
            $nonce = substr($envelope, 1, 12);
            $tag = substr($envelope, -16);
            $ciphertext = substr($envelope, 13, -16);
            return openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        } catch (Throwable $e) {
            return false;
        }
    }
}

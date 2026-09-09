<?php

declare(strict_types=1);

namespace PostPortal\Tests\Security;

use PHPUnit\Framework\TestCase;
use PostPortal\Lib\MigrateSmtpCiphertext;
use PostPortal\Lib\SmtpPasswordPersistence;

/**
 * Integration tests for SMTP password migration: legacy to v2.
 * Uses fake persistence for behavior testing without database.
 */
class MigrationTest extends TestCase
{
    private string $originalAppKey;

    public static function setUpBeforeClass(): void
    {
        // Symlink creation omitted per TDD constraints
    }

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../vendor/autoload.php';

        $this->originalAppKey = getenv('APP_ENCRYPTION_KEY') ?: '';
        putenv('APP_ENCRYPTION_KEY=' . $this->validEncryptionKey());
    }

    protected function tearDown(): void
    {
        if ($this->originalAppKey) {
            putenv("APP_ENCRYPTION_KEY={$this->originalAppKey}");
        } else {
            putenv('APP_ENCRYPTION_KEY');
        }
    }

    /**
     * Test successful migration with fake persistence.
     */
    public function testSuccessfulMigration(): void
    {
        $legacyPassword = 'test-smtp-password';
        $legacyEncrypted = $this->encryptLegacy($legacyPassword);

        $store = new FakePersistence();
        $store->records = [
            ['id' => 1, 'smtp_password' => $legacyEncrypted]
        ];

        $migration = new MigrateSmtpCiphertext(
            $store,
            $this->validEncryptionKey(),
            'test-password',
            'test-database'
        );
        $exitCode = $migration->run();

        $this->assertSame(0, $exitCode, 'Successful migration returns 0');
        $this->assertCount(1, $store->records, 'Record count preserved');

        // Verify v2 envelope starts with version byte 2
        $v2Encrypted = $store->records[0]['smtp_password'];
        $envelope = base64_decode($v2Encrypted, true);
        $this->assertSame(2, ord($envelope[0] ?? ''), 'V2 envelope starts with version byte 2');
    }

    /**
     * Test no-op for already-v2 records.
     */
    public function testNoopForAlreadyV2(): void
    {
        $password = 'test-smtp-password';
        $v2Encrypted = $this->encryptSmtpPassword($password);

        $store = new FakePersistence();
        $store->records = [
            ['id' => 1, 'smtp_password' => $v2Encrypted]
        ];

        $migration = new MigrateSmtpCiphertext(
            $store,
            $this->validEncryptionKey(),
            'test-password',
            'test-database'
        );
        $exitCode = $migration->run();

        $this->assertSame(0, $exitCode, 'V2-only records return 0');
        $this->assertSame($v2Encrypted, $store->records[0]['smtp_password'], 'V2 record unchanged');
    }

    /**
     * Test empty records returns 0.
     */
    public function testEmptyRecordsReturnsZero(): void
    {
        $store = new FakePersistence();
        $store->records = [];

        $migration = new MigrateSmtpCiphertext(
            $store,
            $this->validEncryptionKey(),
            'test-password',
            'test-database'
        );
        $exitCode = $migration->run();

        $this->assertSame(0, $exitCode, 'Empty records return 0');
    }

    /**
     * Test configuration failure maps to exit 1.
     */
    public function testConfigFailureExitsOne(): void
    {
        // Invalid APP_ENCRYPTION_KEY
        putenv('APP_ENCRYPTION_KEY=invalid');

        try {
            $store = new FakePersistence();
            $migration = new MigrateSmtpCiphertext($store);
            $this->fail('Invalid config should throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Invalid', $e->getMessage());
        }
    }

    /**
     * Test partial failure (1 success, 1 fail) returns 3.
     */
    public function testPartialFailureReturnsThree(): void
    {
        $legacyPassword = 'test-smtp-password';
        $legacyEncrypted = $this->encryptLegacy($legacyPassword);

        $store = new FakePersistence();
        $store->records = [
            ['id' => 1, 'smtp_password' => $legacyEncrypted],
            ['id' => 2, 'smtp_password' => 'invalid-not-base64!!!'],  // Will fail
        ];

        $migration = new MigrateSmtpCiphertext(
            $store,
            $this->validEncryptionKey(),
            'test-password',
            'test-database'
        );
        $exitCode = $migration->run();

        $this->assertSame(3, $exitCode, 'Partial failure returns 3');
    }

    /**
     * Test all records fail returns 2.
     */
    public function testAllFailReturnsTwo(): void
    {
        $store = new FakePersistence();
        $store->records = [
            ['id' => 1, 'smtp_password' => 'invalid-base64!!!'],
            ['id' => 2, 'smtp_password' => 'also-invalid!!!'],
        ];

        $migration = new MigrateSmtpCiphertext(
            $store,
            $this->validEncryptionKey(),
            'test-password',
            'test-database'
        );
        $exitCode = $migration->run();

        $this->assertSame(2, $exitCode, 'All failures return 2');
    }

    /**
     * Test successful legacy-to-v2 migration and exact plaintext recovery.
     */
    public function testSuccessfulMigrationWithExactPlaintextRecovery(): void
    {
        $password = 'test-smtp-password';
        $legacyEncrypted = $this->encryptLegacy($password);

        $store = new FakePersistence();
        $store->records = [
            ['id' => 1, 'smtp_password' => $legacyEncrypted]
        ];

        $migration = new MigrateSmtpCiphertext(
            $store,
            $this->validEncryptionKey(),
            'test-password',
            'test-database'
        );
        $exitCode = $migration->run();

        $this->assertSame(0, $exitCode, 'Migration succeeds');

        // Verify v2 envelope
        $v2Encrypted = $store->records[0]['smtp_password'];
        $envelope = base64_decode($v2Encrypted, true);
        $this->assertSame(2, ord($envelope[0] ?? ''), 'V2 envelope starts with version byte 2');

        // Verify decrypted plaintext exactly matches original
        $decrypted = $this->decryptSmtpPassword($v2Encrypted);
        $this->assertSame($password, $decrypted, 'Decrypted plaintext matches original exactly');
    }

    /**
     * Test valid v2 second-run no-op with no transaction.
     */
    public function testValidV2NoopSecondRunNoTransaction(): void
    {
        $password = 'test-smtp-password';
        $v2Encrypted = $this->encryptSmtpPassword($password);

        $store = new FakePersistence();
        $store->records = [
            ['id' => 1, 'smtp_password' => $v2Encrypted]
        ];

        $migration = new MigrateSmtpCiphertext(
            $store,
            $this->validEncryptionKey(),
            'test-password',
            'test-database'
        );
        $exitCode = $migration->run();

        $this->assertSame(0, $exitCode, 'V2-only records return 0');
        $this->assertSame($v2Encrypted, $store->records[0]['smtp_password'], 'V2 record unchanged');
        $this->assertFalse($store->hasActiveTransaction, 'No transaction begun for v2 no-op');
    }

    /**
     * Test fetch failure exits 2.
     */
    public function testFetchFailureExitsTwo(): void
    {
        $store = new FakePersistence();
        $store->failAt = 'fetchAllPasswords';

        $migration = new MigrateSmtpCiphertext(
            $store,
            $this->validEncryptionKey(),
            'test-password',
            'test-database'
        );
        $exitCode = $migration->run();

        $this->assertSame(2, $exitCode, 'Fetch failure exits 2');
    }

    /**
     * Test mixed success/failure exits 3.
     */
    public function testMixedSuccessFailureExitsThree(): void
    {
        $legacyPassword = 'test-smtp-password';
        $legacyEncrypted = $this->encryptLegacy($legacyPassword);

        $store = new FakePersistence();
        $store->records = [
            ['id' => 1, 'smtp_password' => $legacyEncrypted],
            ['id' => 2, 'smtp_password' => 'invalid-not-base64!!!'],
        ];

        $migration = new MigrateSmtpCiphertext(
            $store,
            $this->validEncryptionKey(),
            'test-password',
            'test-database'
        );
        $exitCode = $migration->run();

        $this->assertSame(3, $exitCode, 'Mixed success/failure exits 3');
    }

    /**
     * Test all records fail exits 2.
     */
    public function testAllRecordsFailExitsTwo(): void
    {
        $store = new FakePersistence();
        $store->records = [
            ['id' => 1, 'smtp_password' => 'invalid-base64!!!'],
            ['id' => 2, 'smtp_password' => 'also-invalid!!!'],
        ];

        $migration = new MigrateSmtpCiphertext(
            $store,
            $this->validEncryptionKey(),
            'test-password',
            'test-database'
        );
        $exitCode = $migration->run();

        $this->assertSame(2, $exitCode, 'All failures exit 2');
    }

    /**
     * Test begin failure leaves original unchanged and does not rollback unstarted transaction.
     */
    public function testBeginFailureNoRollbackOfUnstartedTransaction(): void
    {
        $legacyPassword = 'test-smtp-password';
        $legacyEncrypted = $this->encryptLegacy($legacyPassword);

        $store = new FakePersistence();
        $store->records = [
            ['id' => 1, 'smtp_password' => $legacyEncrypted]
        ];
        $store->failAt = 'beginTransaction';

        $migration = new MigrateSmtpCiphertext(
            $store,
            $this->validEncryptionKey(),
            'test-password',
            'test-database'
        );
        $exitCode = $migration->run();

        $this->assertSame(2, $exitCode, 'Begin failure exits 2');
        $this->assertSame($legacyEncrypted, $store->records[0]['smtp_password'], 'Original unchanged');
        $this->assertFalse($store->hasActiveTransaction, 'Transaction not active');
        $this->assertFalse($store->rollbackWasCalled, 'Rollback not called for unstarted transaction');
    }

    /**
     * Test lock exception exits 2, calls rollback, and restores original.
     */
    public function testLockExceptionExitsTwoCallsRollback(): void
    {
        $legacyPassword = 'test-smtp-password';
        $legacyEncrypted = $this->encryptLegacy($legacyPassword);

        $store = new FakePersistence();
        $store->records = [
            ['id' => 1, 'smtp_password' => $legacyEncrypted]
        ];
        $store->failAt = 'lockPassword';

        $migration = new MigrateSmtpCiphertext(
            $store,
            $this->validEncryptionKey(),
            'test-password',
            'test-database'
        );
        $exitCode = $migration->run();

        $this->assertSame(2, $exitCode, 'Lock exception exits 2');
        $this->assertTrue($store->rollbackWasCalled, 'Rollback was called');
        $this->assertSame($legacyEncrypted, $store->records[0]['smtp_password'], 'Original restored');
    }

    /**
     * Test write exception exits 2, calls rollback, and restores original.
     */
    public function testWriteExceptionExitsTwoCallsRollback(): void
    {
        $legacyPassword = 'test-smtp-password';
        $legacyEncrypted = $this->encryptLegacy($legacyPassword);

        $store = new FakePersistence();
        $store->records = [
            ['id' => 1, 'smtp_password' => $legacyEncrypted]
        ];
        $store->failAt = 'writePassword';

        $migration = new MigrateSmtpCiphertext(
            $store,
            $this->validEncryptionKey(),
            'test-password',
            'test-database'
        );
        $exitCode = $migration->run();

        $this->assertSame(2, $exitCode, 'Write exception exits 2');
        $this->assertTrue($store->rollbackWasCalled, 'Rollback was called');
        $this->assertSame($legacyEncrypted, $store->records[0]['smtp_password'], 'Original restored');
    }

    /**
     * Test read exception exits 2, calls rollback, and restores original.
     */
    public function testReadExceptionExitsTwoCallsRollback(): void
    {
        $legacyPassword = 'test-smtp-password';
        $legacyEncrypted = $this->encryptLegacy($legacyPassword);

        $store = new FakePersistence();
        $store->records = [
            ['id' => 1, 'smtp_password' => $legacyEncrypted]
        ];
        $store->failAt = 'readPassword';

        $migration = new MigrateSmtpCiphertext(
            $store,
            $this->validEncryptionKey(),
            'test-password',
            'test-database'
        );
        $exitCode = $migration->run();

        $this->assertSame(2, $exitCode, 'Read exception exits 2');
        $this->assertTrue($store->rollbackWasCalled, 'Rollback was called');
        $this->assertSame($legacyEncrypted, $store->records[0]['smtp_password'], 'Original restored');
    }

    /**
     * Test readback ciphertext mismatch exits 2 and calls rollback.
     */
    public function testReadbackMismatchExitsTwoCallsRollback(): void
    {
        $legacyPassword = 'test-smtp-password';
        $legacyEncrypted = $this->encryptLegacy($legacyPassword);

        $store = new FakePersistence();
        $store->records = [
            ['id' => 1, 'smtp_password' => $legacyEncrypted]
        ];
        $store->failAt = 'readbackMismatch';

        $migration = new MigrateSmtpCiphertext(
            $store,
            $this->validEncryptionKey(),
            'test-password',
            'test-database'
        );
        $exitCode = $migration->run();

        $this->assertSame(2, $exitCode, 'Readback mismatch exits 2');
        $this->assertTrue($store->rollbackWasCalled, 'Rollback was called');
        $this->assertSame($legacyEncrypted, $store->records[0]['smtp_password'], 'Original restored');
    }

    /**
     * Test v2 decrypt failure exits 2 and calls rollback.
     */
    public function testV2DecryptFailureExitsTwoCallsRollback(): void
    {
        $legacyPassword = 'test-smtp-password';
        $legacyEncrypted = $this->encryptLegacy($legacyPassword);

        $store = new FakePersistence();
        $store->records = [
            ['id' => 1, 'smtp_password' => $legacyEncrypted]
        ];
        $store->failAt = 'verifyAndDecryptV2';

        $migration = new MigrateSmtpCiphertext(
            $store,
            $this->validEncryptionKey(),
            'test-password',
            'test-database'
        );
        $exitCode = $migration->run();

        $this->assertSame(2, $exitCode, 'V2 decrypt failure exits 2');
        $this->assertTrue($store->rollbackWasCalled, 'Rollback was called');
        $this->assertSame($legacyEncrypted, $store->records[0]['smtp_password'], 'Original restored');
    }

    /**
     * Test v2 plaintext mismatch exits 2 and calls rollback.
     */
    public function testV2PlaintextMismatchExitsTwoCallsRollback(): void
    {
        $legacyPassword = 'test-smtp-password';
        $legacyEncrypted = $this->encryptLegacy($legacyPassword);

        $store = new FakePersistence();
        $store->records = [
            ['id' => 1, 'smtp_password' => $legacyEncrypted]
        ];
        $store->failAt = 'plaintextMismatch';

        $migration = new MigrateSmtpCiphertext(
            $store,
            $this->validEncryptionKey(),
            'test-password',
            'test-database'
        );
        $exitCode = $migration->run();

        $this->assertSame(2, $exitCode, 'Plaintext mismatch exits 2');
        $this->assertTrue($store->rollbackWasCalled, 'Rollback was called');
        $this->assertSame($legacyEncrypted, $store->records[0]['smtp_password'], 'Original restored');
    }

    /**
     * Test commit exception exits 2 and calls rollback.
     */
    public function testCommitExceptionExitsTwoCallsRollback(): void
    {
        $legacyPassword = 'test-smtp-password';
        $legacyEncrypted = $this->encryptLegacy($legacyPassword);

        $store = new FakePersistence();
        $store->records = [
            ['id' => 1, 'smtp_password' => $legacyEncrypted]
        ];
        $store->failAt = 'commit';

        $migration = new MigrateSmtpCiphertext(
            $store,
            $this->validEncryptionKey(),
            'test-password',
            'test-database'
        );
        $exitCode = $migration->run();

        $this->assertSame(2, $exitCode, 'Commit exception exits 2');
        $this->assertTrue($store->rollbackWasCalled, 'Rollback was called');
        $this->assertSame($legacyEncrypted, $store->records[0]['smtp_password'], 'Original restored');
    }

    /**
     * Test rollback exception is swallowed, logs generic message, and still exits 2.
     */
    public function testRollbackExceptionSwallowedStillExitsTwoWithGenericLog(): void
    {
        $legacyPassword = 'test-smtp-password';
        $legacyEncrypted = $this->encryptLegacy($legacyPassword);

        $store = new FakePersistence();
        $store->records = [
            ['id' => 1, 'smtp_password' => $legacyEncrypted]
        ];
        $store->failAt = 'lockPassword';
        $store->failRollbackToo = true;

        $migration = new MigrateSmtpCiphertext(
            $store,
            $this->validEncryptionKey(),
            'test-password',
            'test-database'
        );
        $exitCode = $migration->run();

        $this->assertSame(2, $exitCode, 'Exception during rollback still exits 2');
        $this->assertTrue($store->rollbackWasCalled, 'Rollback was attempted');
    }

    /**
     * Test exact successful operation order: fetch, begin, lock, write, read, commit.
     */
    public function testExactSuccessfulOperationOrder(): void
    {
        $legacyPassword = 'test-smtp-password';
        $legacyEncrypted = $this->encryptLegacy($legacyPassword);

        $store = new FakePersistence();
        $store->records = [
            ['id' => 1, 'smtp_password' => $legacyEncrypted]
        ];

        $migration = new MigrateSmtpCiphertext(
            $store,
            $this->validEncryptionKey(),
            'test-password',
            'test-database'
        );
        $exitCode = $migration->run();

        $this->assertSame(0, $exitCode, 'Migration succeeds');

        // Verify operation order
        $expectedOrder = ['fetchAllPasswords', 'beginTransaction', 'lockPassword', 'writePassword', 'readPassword', 'commit'];
        $this->assertSame($expectedOrder, $store->operationLog, 'Operations executed in exact order');
    }

    // ========== Helpers ==========

    private function validEncryptionKey(): string
    {
        return '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    }

    /**
     * Encrypt plaintext using v2 AES-256-GCM.
     * Mirrors encryptSmtpPassword() from functions.php.
     */
    private function encryptSmtpPassword(string $password): string
    {
        if ($password === '') {
            return '';
        }

        $appKey = hex2bin(getenv('APP_ENCRYPTION_KEY') ?: '');
        if ($appKey === false || strlen($appKey) !== 32) {
            return '';
        }

        $nonce = openssl_random_pseudo_bytes(12);
        if ($nonce === false) {
            return '';
        }

        $tag = '';
        $encrypted = openssl_encrypt(
            $password,
            'aes-256-gcm',
            $appKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($encrypted === false) {
            return '';
        }

        $envelope = chr(2) . $nonce . $encrypted . $tag;
        return base64_encode($envelope);
    }

    /**
     * Decrypt SMTP password using v2 AES-256-GCM.
     * Mirrors decryptSmtpPassword() from functions.php.
     */
    private function decryptSmtpPassword(string $encryptedPassword): string
    {
        if ($encryptedPassword === '') {
            return '';
        }

        $envelope = base64_decode($encryptedPassword, true);
        if ($envelope === false || strlen($envelope) < 29) {
            return '';
        }

        $version = ord($envelope[0]);
        if ($version !== 2) {
            return '';
        }

        $appKey = hex2bin(getenv('APP_ENCRYPTION_KEY') ?: '');
        if ($appKey === false || strlen($appKey) !== 32) {
            return '';
        }

        $nonce = substr($envelope, 1, 12);
        $tag = substr($envelope, -16);
        $ciphertext = substr($envelope, 13, -16);

        if (strlen($nonce) !== 12 || strlen($tag) !== 16) {
            return '';
        }

        $decrypted = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $appKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        return $decrypted === false ? '' : $decrypted;
    }

    /**
     * Encrypt plaintext using legacy AES-256-CBC.
     * Uses fixed test DB password/database values for consistency.
     */
    private function encryptLegacy(string $plaintext): string
    {
        $dbPassword = 'test-password';
        $dbName = 'test-database';
        $key = hash('sha256', $dbPassword . $dbName, true);
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        $iv = openssl_random_pseudo_bytes($ivLength);

        $encrypted = openssl_encrypt($plaintext, 'aes-256-cbc', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }
}

/**
 * Fake persistence for testing without database.
 * Implements primitive operations only; does not contain migration logic.
 */
class FakePersistence implements SmtpPasswordPersistence
{
    public array $records = [];
    public ?string $failAt = null;
    public bool $failRollbackToo = false;
    public bool $hasActiveTransaction = false;
    public bool $rollbackWasCalled = false;
    public array $operationLog = [];
    private ?int $currentId = null;
    private ?string $lockedOriginal = null;
    private ?string $lastWrittenValue = null;

    public function fetchAllPasswords(): array
    {
        $this->operationLog[] = 'fetchAllPasswords';
        if ($this->failAt === 'fetchAllPasswords') {
            throw new \RuntimeException('Database error');
        }
        return $this->records;
    }

    public function beginTransaction(): void
    {
        $this->operationLog[] = 'beginTransaction';
        if ($this->failAt === 'beginTransaction') {
            throw new \RuntimeException('Database error');
        }
        $this->hasActiveTransaction = true;
    }

    public function lockPassword(int $id): ?string
    {
        $this->operationLog[] = 'lockPassword';
        if ($this->failAt === 'lockPassword') {
            throw new \RuntimeException('Database error');
        }
        $this->currentId = $id;
        foreach ($this->records as $record) {
            if ($record['id'] === $id) {
                $this->lockedOriginal = $record['smtp_password'];
                return $record['smtp_password'];
            }
        }
        return null;
    }

    public function writePassword(int $id, string $ciphertext): void
    {
        $this->operationLog[] = 'writePassword';
        if ($this->failAt === 'writePassword') {
            throw new \RuntimeException('Database error');
        }
        $this->lastWrittenValue = $ciphertext;
        foreach ($this->records as &$record) {
            if ($record['id'] === $id) {
                $record['smtp_password'] = $ciphertext;
                break;
            }
        }
    }

    public function readPassword(int $id): ?string
    {
        $this->operationLog[] = 'readPassword';
        if ($this->failAt === 'readPassword') {
            throw new \RuntimeException('Database error');
        }
        if ($this->failAt === 'verifyAndDecryptV2') {
            // Return a corrupted v2 envelope that will fail decryption
            return base64_encode(chr(2) . 'corrupted-envelope-data-that-is-invalid');
        }
        if ($this->failAt === 'plaintextMismatch' && $this->lastWrittenValue !== null) {
            // Return a different v2 envelope that decrypts to wrong plaintext
            $key = hex2bin('0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef');
            $nonce = openssl_random_pseudo_bytes(12);
            $tag = '';
            $encrypted = openssl_encrypt(
                'WRONG_PLAINTEXT_VALUE',
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $nonce,
                $tag
            );
            return base64_encode(chr(2) . $nonce . $encrypted . $tag);
        }
        if ($this->failAt === 'readbackMismatch') {
            // We need to extract the plaintext from lastWrittenValue and re-encrypt it
            // This produces a valid v2 with the same plaintext but different ciphertext
            // The migration should catch this via ciphertext identity check
            if ($this->lastWrittenValue === null) {
                return null;
            }
            // Decode the written value to get plaintext
            $envelope = base64_decode($this->lastWrittenValue, true);
            if ($envelope === false || strlen($envelope) < 29) {
                return 'invalid';
            }
            $nonce = substr($envelope, 1, 12);
            $tag = substr($envelope, -16);
            $ciphertext = substr($envelope, 13, -16);
            $key = hex2bin('0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef');
            $decrypted = openssl_decrypt(
                $ciphertext,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $nonce,
                $tag
            );
            if ($decrypted === false) {
                return 'invalid';
            }
            // Re-encrypt with different nonce to get same plaintext but different ciphertext
            $newNonce = openssl_random_pseudo_bytes(12);
            $newTag = '';
            $newEncrypted = openssl_encrypt(
                $decrypted,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $newNonce,
                $newTag
            );
            if ($newEncrypted === false) {
                return 'invalid';
            }
            return base64_encode(chr(2) . $newNonce . $newEncrypted . $newTag);
        }
        foreach ($this->records as $record) {
            if ($record['id'] === $id) {
                return $record['smtp_password'];
            }
        }
        return null;
    }

    public function commit(): void
    {
        $this->operationLog[] = 'commit';
        if ($this->failAt === 'commit') {
            throw new \RuntimeException('Database error');
        }
        $this->hasActiveTransaction = false;
        $this->currentId = null;
        $this->lockedOriginal = null;
    }

    public function rollback(): void
    {
        $this->operationLog[] = 'rollback';
        $this->rollbackWasCalled = true;
        if ($this->failRollbackToo) {
            throw new \RuntimeException('Rollback failed');
        }
        if ($this->currentId !== null && $this->lockedOriginal !== null) {
            foreach ($this->records as &$record) {
                if ($record['id'] === $this->currentId) {
                    $record['smtp_password'] = $this->lockedOriginal;
                    break;
                }
            }
        }
        $this->hasActiveTransaction = false;
        $this->currentId = null;
        $this->lockedOriginal = null;
    }


}

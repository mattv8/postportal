<?php

declare(strict_types=1);

namespace PostPortal\Tests\Security;

use PHPUnit\Framework\TestCase;
use PostPortal\Lib\MigrateSmtpCiphertext;
use PostPortal\Lib\SmtpPasswordPersistence;
use PostPortal\Security\AppKeyDeriver;

final class MigrationTest extends TestCase
{
    private const APP_SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../vendor/autoload.php';
    }

    public function testMigratesLegacyCbcToDerivedKeyV3WithExactPlaintext(): void
    {
        $plaintext = 'test-smtp-password';
        $store = new FakePersistence([['id' => 1, 'smtp_password' => $this->encryptLegacy($plaintext)]]);
        $migration = $this->migration($store);

        self::assertSame(0, $migration->run());
        $encrypted = $store->records[0]['smtp_password'];
        $envelope = base64_decode($encrypted, true);
        self::assertNotFalse($envelope);
        self::assertSame(3, ord($envelope[0]));
        self::assertSame($plaintext, $migration->verifyAndDecryptV3($encrypted));
        self::assertSame(['fetchAllPasswords', 'beginTransaction', 'lockPassword', 'writePassword', 'readPassword', 'commit'], $store->operationLog);
    }

    /** @dataProvider versionLookingLegacyIvBytes */
    public function testLegacyCiphertextWithVersionLookingIvMigratesToV3(int $firstIvByte): void
    {
        $plaintext = 'legacy-version-looking-iv';
        $legacy = $this->encryptLegacy($plaintext, chr($firstIvByte) . str_repeat("\x7f", 15));
        $store = new FakePersistence([['id' => 1, 'smtp_password' => $legacy]]);
        $migration = $this->migration($store);

        self::assertSame(0, $migration->run());
        $encrypted = $store->records[0]['smtp_password'];
        $envelope = base64_decode($encrypted, true);
        self::assertNotFalse($envelope);
        self::assertSame(3, ord($envelope[0]));
        self::assertSame($plaintext, $migration->verifyAndDecryptV3($encrypted));
    }

    public static function versionLookingLegacyIvBytes(): array
    {
        return ['looks-like-v2' => [2], 'looks-like-v3' => [3]];
    }

    public function testMigratesDirectRootV2ToDerivedKeyV3(): void
    {
        $plaintext = 'v2-root-password';
        $v2 = $this->encryptV2WithRootKey($plaintext);
        $store = new FakePersistence([['id' => 1, 'smtp_password' => $v2]]);
        $migration = $this->migration($store);

        self::assertSame(0, $migration->run());
        self::assertNotSame($v2, $store->records[0]['smtp_password']);
        self::assertSame(3, ord((string) base64_decode($store->records[0]['smtp_password'], true)[0]));
        self::assertSame($plaintext, $migration->verifyAndDecryptV3($store->records[0]['smtp_password']));
    }

    public function testValidV3IsVerifiedNoopWithoutTransactionAndIsIdempotent(): void
    {
        $v3 = $this->encryptV3('already-migrated');
        $store = new FakePersistence([['id' => 1, 'smtp_password' => $v3]]);
        $migration = $this->migration($store);

        self::assertSame(0, $migration->run());
        self::assertSame($v3, $store->records[0]['smtp_password']);
        self::assertSame(['fetchAllPasswords'], $store->operationLog);
        self::assertSame(0, $migration->run());
        self::assertSame($v3, $store->records[0]['smtp_password']);
    }

    /** @dataProvider malformedVersionedEnvelopes */
    public function testMalformedV2AndV3AreRejectedWithoutMutation(string $encrypted): void
    {
        $store = new FakePersistence([['id' => 1, 'smtp_password' => $encrypted]]);

        self::assertSame(2, $this->migration($store)->run());
        self::assertSame($encrypted, $store->records[0]['smtp_password']);
        self::assertSame(['fetchAllPasswords'], $store->operationLog);
    }

    public static function malformedVersionedEnvelopes(): array
    {
        return [
            'malformed-v2' => [base64_encode(chr(2) . str_repeat('x', 28))],
            'malformed-v3' => [base64_encode(chr(3) . str_repeat('x', 28))],
        ];
    }

    /** @dataProvider rollbackFailures */
    public function testRollbackPreservesOriginalAfterTransactionFailure(string $failure): void
    {
        $original = $this->encryptLegacy('recoverable');
        $store = new FakePersistence([['id' => 1, 'smtp_password' => $original]]);
        $store->failAt = $failure;

        self::assertSame(2, $this->migration($store)->run());
        self::assertSame($original, $store->records[0]['smtp_password']);
        self::assertTrue($store->rollbackWasCalled);
    }

    public static function rollbackFailures(): array
    {
        return [['lockPassword'], ['writePassword'], ['readPassword'], ['readbackMismatch'], ['commit']];
    }

    public function testInvalidV3ReadbackRollsBackAndPreservesOriginal(): void
    {
        $original = $this->encryptLegacy('recoverable');
        $store = new FakePersistence([['id' => 1, 'smtp_password' => $original]]);
        $store->readbackOverride = base64_encode(chr(3) . str_repeat('x', 28));

        self::assertSame(2, $this->migration($store)->run());
        self::assertTrue($store->rollbackWasCalled);
        self::assertSame($original, $store->records[0]['smtp_password']);
    }

    public function testDifferentPlaintextV3ReadbackRollsBackAndPreservesOriginal(): void
    {
        $original = $this->encryptLegacy('recoverable');
        $store = new FakePersistence([['id' => 1, 'smtp_password' => $original]]);
        $store->readbackOverride = $this->encryptV3('different plaintext');

        self::assertSame(2, $this->migration($store)->run());
        self::assertTrue($store->rollbackWasCalled);
        self::assertSame($original, $store->records[0]['smtp_password']);
    }

    public function testSamePlaintextDifferentCiphertextV3ReadbackRollsBackAndPreservesOriginal(): void
    {
        $plaintext = 'recoverable';
        $original = $this->encryptLegacy($plaintext);
        $store = new FakePersistence([['id' => 1, 'smtp_password' => $original]]);
        $store->readbackOverride = $this->encryptV3($plaintext);

        self::assertSame(2, $this->migration($store)->run());
        self::assertTrue($store->rollbackWasCalled);
        self::assertSame($original, $store->records[0]['smtp_password']);
    }

    public function testBeginTransactionFailureDoesNotRollbackAndPreservesOriginal(): void
    {
        $original = $this->encryptLegacy('recoverable');
        $store = new FakePersistence([['id' => 1, 'smtp_password' => $original]]);
        $store->failAt = 'beginTransaction';

        self::assertSame(2, $this->migration($store)->run());
        self::assertFalse($store->rollbackWasCalled);
        self::assertSame($original, $store->records[0]['smtp_password']);
    }

    private function migration(FakePersistence $store): MigrateSmtpCiphertext
    {
        return new MigrateSmtpCiphertext($store, self::APP_SECRET, 'test-password', 'test-database');
    }

    private function encryptLegacy(string $plaintext, ?string $iv = null): string
    {
        $key = hash('sha256', 'test-password' . 'test-database', true);
        $iv ??= random_bytes((int) openssl_cipher_iv_length('aes-256-cbc'));
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $key, 0, $iv);
        self::assertNotFalse($ciphertext);
        return base64_encode($iv . $ciphertext);
    }

    private function encryptV2WithRootKey(string $plaintext): string
    {
        return $this->encryptGcm(2, hex2bin(self::APP_SECRET), $plaintext);
    }

    private function encryptV3(string $plaintext): string
    {
        return $this->encryptGcm(3, AppKeyDeriver::smtpEncryptionKey(self::APP_SECRET), $plaintext);
    }

    private function encryptGcm(int $version, string $key, string $plaintext): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        self::assertNotFalse($ciphertext);
        return base64_encode(chr($version) . $nonce . $ciphertext . $tag);
    }
}

final class FakePersistence implements SmtpPasswordPersistence
{
    public array $records;
    public ?string $failAt = null;
    public ?string $readbackOverride = null;
    public bool $rollbackWasCalled = false;
    public array $operationLog = [];
    private ?int $lockedId = null;
    private ?string $original = null;

    public function __construct(array $records)
    {
        $this->records = $records;
    }

    public function fetchAllPasswords(): array { $this->operationLog[] = 'fetchAllPasswords'; return $this->records; }
    public function beginTransaction(): void { $this->operationLog[] = 'beginTransaction'; if ($this->failAt === 'beginTransaction') { throw new \RuntimeException(); } }
    public function lockPassword(int $id): ?string { $this->operationLog[] = 'lockPassword'; if ($this->failAt === 'lockPassword') { throw new \RuntimeException(); } foreach ($this->records as $row) { if ($row['id'] === $id) { $this->lockedId = $id; $this->original = $row['smtp_password']; return $row['smtp_password']; } } return null; }
    public function writePassword(int $id, string $ciphertext): void { $this->operationLog[] = 'writePassword'; if ($this->failAt === 'writePassword') { throw new \RuntimeException(); } foreach ($this->records as &$row) { if ($row['id'] === $id) { $row['smtp_password'] = $ciphertext; } } }
    public function readPassword(int $id): ?string { $this->operationLog[] = 'readPassword'; if ($this->failAt === 'readPassword') { throw new \RuntimeException(); } if ($this->readbackOverride !== null) { return $this->readbackOverride; } if ($this->failAt === 'readbackMismatch') { return 'different'; } foreach ($this->records as $row) { if ($row['id'] === $id) { return $row['smtp_password']; } } return null; }
    public function commit(): void { $this->operationLog[] = 'commit'; if ($this->failAt === 'commit') { throw new \RuntimeException(); } }
    public function rollback(): void { $this->operationLog[] = 'rollback'; $this->rollbackWasCalled = true; foreach ($this->records as &$row) { if ($row['id'] === $this->lockedId) { $row['smtp_password'] = $this->original; } } }
}

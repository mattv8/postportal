<?php

declare(strict_types=1);

namespace PostPortal\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Test suite for cryptographic primitives: v2 SMTP encryption, v2 unsubscribe tokens, canonical URL.
 *
 * All tests exercise real crypto and validation logic.
 */
class SecretEncryptionTest extends TestCase
{
    private string $originalAppKey;
    private string $originalUnsubKey;
    private string $originalAppUrl;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../vendor/autoload.php';
        require_once __DIR__ . '/../../src/functions.php';

        $this->originalAppKey = getenv('APP_ENCRYPTION_KEY') ?: '';
        $this->originalUnsubKey = getenv('UNSUBSCRIBE_SECRET') ?: '';
        $this->originalAppUrl = getenv('APP_URL') ?: '';
    }

    protected function tearDown(): void
    {
        if ($this->originalAppKey) {
            putenv("APP_ENCRYPTION_KEY={$this->originalAppKey}");
        } else {
            putenv('APP_ENCRYPTION_KEY');
        }

        if ($this->originalUnsubKey) {
            putenv("UNSUBSCRIBE_SECRET={$this->originalUnsubKey}");
        } else {
            putenv('UNSUBSCRIBE_SECRET');
        }

        if ($this->originalAppUrl) {
            putenv("APP_URL={$this->originalAppUrl}");
        } else {
            putenv('APP_URL');
        }
    }

    // ========== SMTP Encryption Tests ==========

    public function testSmtpEncryptionRoundTrip(): void
    {
        putenv('APP_ENCRYPTION_KEY=' . $this->validEncryptionKey());

        $password = 'test-smtp-password-123456789';
        $encrypted = encryptSmtpPassword($password);

        $this->assertNotEmpty($encrypted, 'Encrypted password must not be empty');
        $this->assertNotSame($password, $encrypted, 'Encrypted must differ from plaintext');

        $decrypted = decryptSmtpPassword($encrypted);
        $this->assertSame($password, $decrypted, 'Decrypted must match original');
    }

    public function testSmtpEncryptionRandomNonce(): void
    {
        putenv('APP_ENCRYPTION_KEY=' . $this->validEncryptionKey());

        $password = 'same-password';
        $enc1 = encryptSmtpPassword($password);
        $enc2 = encryptSmtpPassword($password);

        // Random nonce means different ciphertexts
        $this->assertNotSame($enc1, $enc2, 'Same password with random nonce must produce different ciphertexts');

        // But both decrypt to original
        $this->assertSame($password, decryptSmtpPassword($enc1));
        $this->assertSame($password, decryptSmtpPassword($enc2));
    }

    public function testSmtpEncryptionTamperDetection(): void
    {
        putenv('APP_ENCRYPTION_KEY=' . $this->validEncryptionKey());

        $password = 'secret-password';
        $encrypted = encryptSmtpPassword($password);

        // Tamper: flip a bit in the ciphertext portion
        $envelope = base64_decode($encrypted, true);
        $tampered = base64_encode(
            substr($envelope, 0, 20) .
            chr(ord(substr($envelope, 20, 1)) ^ 0xFF) .
            substr($envelope, 21)
        );

        $decrypted = decryptSmtpPassword($tampered);
        $this->assertNotSame($password, $decrypted, 'Tampered ciphertext must not decrypt to original');
    }

    public function testSmtpEncryptionVersionMutation(): void
    {
        putenv('APP_ENCRYPTION_KEY=' . $this->validEncryptionKey());

        $password = 'test';
        $encrypted = encryptSmtpPassword($password);

        // Mutate version byte
        $envelope = base64_decode($encrypted, true);
        $mutated = base64_encode(chr(1) . substr($envelope, 1)); // Change v2 to v1
        $decrypted = decryptSmtpPassword($mutated);

        $this->assertNotSame($password, $decrypted, 'Different version must not decrypt');
    }

    public function testSmtpEncryptionNonceMutation(): void
    {
        putenv('APP_ENCRYPTION_KEY=' . $this->validEncryptionKey());

        $password = 'test';
        $encrypted = encryptSmtpPassword($password);

        // Mutate nonce byte
        $envelope = base64_decode($encrypted, true);
        $nonce = substr($envelope, 1, 12);
        $mutatedNonce = substr_replace($nonce, chr(ord($nonce[0]) ^ 0xFF), 0, 1);
        $mutated = base64_encode(chr(2) . $mutatedNonce . substr($envelope, 13));
        $decrypted = decryptSmtpPassword($mutated);

        $this->assertNotSame($password, $decrypted, 'Tampered nonce must fail auth');
    }

    public function testSmtpEncryptionTagMutation(): void
    {
        putenv('APP_ENCRYPTION_KEY=' . $this->validEncryptionKey());

        $password = 'test';
        $encrypted = encryptSmtpPassword($password);

        // Mutate auth tag (last 16 bytes)
        $envelope = base64_decode($encrypted, true);
        $tag = substr($envelope, -16);
        $mutatedTag = substr_replace($tag, chr(ord($tag[0]) ^ 0xFF), 0, 1);
        $mutated = base64_encode(substr($envelope, 0, -16) . $mutatedTag);
        $decrypted = decryptSmtpPassword($mutated);

        $this->assertNotSame($password, $decrypted, 'Tampered tag must fail auth');
    }

    public function testSmtpEncryptionDifferentKeysFail(): void
    {
        putenv('APP_ENCRYPTION_KEY=' . $this->validEncryptionKey());
        $password = 'test';
        $encrypted = encryptSmtpPassword($password);

        putenv('APP_ENCRYPTION_KEY=' . $this->differentEncryptionKey());
        $decrypted = decryptSmtpPassword($encrypted);

        $this->assertNotSame($password, $decrypted, 'Different key must not decrypt');
    }

    public function testSmtpEncryptionMissingKeyFailsClosed(): void
    {
        putenv('APP_ENCRYPTION_KEY');

        $encrypted = encryptSmtpPassword('test');
        $this->assertSame('', $encrypted, 'Missing key must return empty string');
    }

    public function testSmtpEncryptionInvalidKeyFormatFailsClosed(): void
    {
        // Only 63 hex chars (must be 64)
        putenv('APP_ENCRYPTION_KEY=' . str_repeat('a', 63));

        $encrypted = encryptSmtpPassword('test');
        $this->assertSame('', $encrypted, 'Invalid key format must return empty string');
    }

    public function testSmtpEncryptionEmptyPasswordEncryptsToEmpty(): void
    {
        putenv('APP_ENCRYPTION_KEY=' . $this->validEncryptionKey());

        $encrypted = encryptSmtpPassword('');
        $this->assertSame('', $encrypted, 'Empty password encrypts to empty');
    }

    // ========== Unsubscribe Token Tests ==========

    public function testUnsubscribeTokenRoundTrip(): void
    {
        putenv('UNSUBSCRIBE_SECRET=' . $this->validUnsubscribeSecret());

        $email = 'user@example.com';
        $token = generateUnsubscribeToken($email);

        $this->assertNotEmpty($token, 'Token must not be empty');
        $this->assertNotSame($email, $token, 'Token must differ from email');

        $validated = validateUnsubscribeToken($token);
        $this->assertSame($email, $validated, 'Validated email must match original');
    }

    public function testUnsubscribeTokenDifferentEmails(): void
    {
        putenv('UNSUBSCRIBE_SECRET=' . $this->validUnsubscribeSecret());

        $token1 = generateUnsubscribeToken('user1@example.com');
        $token2 = generateUnsubscribeToken('user2@example.com');

        $this->assertNotSame($token1, $token2, 'Different emails produce different tokens');
    }

    public function testUnsubscribeTokenTamperDetection(): void
    {
        putenv('UNSUBSCRIBE_SECRET=' . $this->validUnsubscribeSecret());

        $email = 'user@example.com';
        $token = generateUnsubscribeToken($email);

        // Tamper: flip a bit
        $canonical = strtr($token, '-_', '+/');
        $decoded = base64_decode($canonical, true);
        $tampered = base64_encode(
            substr($decoded, 0, 10) .
            chr(ord(substr($decoded, 10, 1)) ^ 0xFF) .
            substr($decoded, 11)
        );
        $tampered = strtr($tampered, '+/', '-_');

        $validated = validateUnsubscribeToken($tampered);
        $this->assertFalse($validated, 'Tampered token must be rejected');
    }

    public function testUnsubscribeTokenRejectsLegacy(): void
    {
        putenv('UNSUBSCRIBE_SECRET=' . $this->validUnsubscribeSecret());

        // Legacy format: no version/purpose
        $legacyPayload = json_encode(['email' => 'user@example.com']);
        $legacyToken = strtr(base64_encode($legacyPayload), '+/', '-_');

        $validated = validateUnsubscribeToken($legacyToken);
        $this->assertFalse($validated, 'Legacy format must be rejected');
    }

    public function testUnsubscribeTokenRejectsWrongPurpose(): void
    {
        putenv('UNSUBSCRIBE_SECRET=' . $this->validUnsubscribeSecret());

        // v2 but wrong purpose
        $wrongPayload = json_encode(['v' => 2, 'purpose' => 'other', 'email' => 'user@example.com']);
        $wrongSignature = hash_hmac('sha256', $wrongPayload, getenv('UNSUBSCRIBE_SECRET'));
        $token = strtr(base64_encode($wrongPayload . '|' . $wrongSignature), '+/', '-_');

        $validated = validateUnsubscribeToken($token);
        $this->assertFalse($validated, 'Wrong purpose must be rejected');
    }

    public function testUnsubscribeTokenRejectsDatabasePasswordSigning(): void
    {
        putenv('UNSUBSCRIBE_SECRET=' . $this->validUnsubscribeSecret());

        $email = 'user@example.com';

        // Try to forge a token with MYSQL_PASSWORD
        $cfg = getDbConfig();
        $payload = json_encode(['v' => 2, 'purpose' => 'unsubscribe', 'email' => $email]);
        $wrongSig = hash_hmac('sha256', $payload, $cfg['password']);
        $forgedToken = strtr(base64_encode($payload . '|' . $wrongSig), '+/', '-_');

        // Real token with UNSUBSCRIBE_SECRET
        $realToken = generateUnsubscribeToken($email);

        // Forged must fail, real must pass
        $this->assertFalse(validateUnsubscribeToken($forgedToken), 'Database-password signed token must be rejected');
        $this->assertSame($email, validateUnsubscribeToken($realToken), 'Real token must validate');
    }

    public function testUnsubscribeTokenValidatesEmail(): void
    {
        putenv('UNSUBSCRIBE_SECRET=' . $this->validUnsubscribeSecret());

        // Create v2 token with invalid email
        $payload = json_encode(['v' => 2, 'purpose' => 'unsubscribe', 'email' => 'not-an-email']);
        $sig = hash_hmac('sha256', $payload, getenv('UNSUBSCRIBE_SECRET'));
        $token = strtr(base64_encode($payload . '|' . $sig), '+/', '-_');

        $validated = validateUnsubscribeToken($token);
        $this->assertFalse($validated, 'Invalid email must be rejected');
    }

    public function testUnsubscribeTokenValidatesEmailIsString(): void
    {
        putenv('UNSUBSCRIBE_SECRET=' . $this->validUnsubscribeSecret());

        // Create v2 token with email as int
        $payload = json_encode(['v' => 2, 'purpose' => 'unsubscribe', 'email' => 123]);
        $sig = hash_hmac('sha256', $payload, getenv('UNSUBSCRIBE_SECRET'));
        $token = strtr(base64_encode($payload . '|' . $sig), '+/', '-_');

        $validated = validateUnsubscribeToken($token);
        $this->assertFalse($validated, 'Non-string email must be rejected');
    }

    public function testUnsubscribeTokenMissingSecretFailsClosed(): void
    {
        putenv('UNSUBSCRIBE_SECRET');

        $token = generateUnsubscribeToken('user@example.com');
        $this->assertSame('', $token, 'Missing secret must return empty token');
    }

    public function testUnsubscribeTokenSecretTooShortFailsClosed(): void
    {
        putenv('UNSUBSCRIBE_SECRET=' . str_repeat('x', 31)); // 31 chars, need 32+

        $token = generateUnsubscribeToken('user@example.com');
        $this->assertSame('', $token, 'Secret < 32 bytes must return empty token');
    }

    // ========== Canonical URL Tests ==========

    public function testCanonicalUrlNormalizesSuffix(): void
    {
        putenv('APP_URL=https://example.com/');

        $url = getCanonicalAppUrl();
        $this->assertSame('https://example.com', $url, 'Trailing slash must be removed');
    }

    public function testCanonicalUrlPreservesPath(): void
    {
        putenv('APP_URL=https://example.com/app');

        $url = getCanonicalAppUrl();
        $this->assertSame('https://example.com/app', $url, 'Path must be preserved');
    }

    public function testCanonicalUrlPreservesPort(): void
    {
        putenv('APP_URL=https://example.com:8080');

        $url = getCanonicalAppUrl();
        $this->assertSame('https://example.com:8080', $url, 'Port must be preserved');
    }

    public function testCanonicalUrlRejectsRelativeUrl(): void
    {
        putenv('APP_URL=/relative');

        try {
            getCanonicalAppUrl();
            $this->fail('Relative URL should throw');
        } catch (\Exception $e) {
            $this->assertStringContainsString('scheme', strtolower($e->getMessage()));
        }
    }

    public function testCanonicalUrlRejectsCredentials(): void
    {
        putenv('APP_URL=https://user:pass@example.com');

        try {
            getCanonicalAppUrl();
            $this->fail('URL with credentials should throw');
        } catch (\Exception $e) {
            $this->assertStringContainsString('credential', strtolower($e->getMessage()));
        }
    }

    public function testCanonicalUrlRejectsQuery(): void
    {
        putenv('APP_URL=https://example.com?foo=bar');

        try {
            getCanonicalAppUrl();
            $this->fail('URL with query should throw');
        } catch (\Exception $e) {
            $this->assertStringContainsString('query', strtolower($e->getMessage()));
        }
    }

    public function testCanonicalUrlRejectsFragment(): void
    {
        putenv('APP_URL=https://example.com#section');

        try {
            getCanonicalAppUrl();
            $this->fail('URL with fragment should throw');
        } catch (\Exception $e) {
            $this->assertStringContainsString('fragment', strtolower($e->getMessage()));
        }
    }

    public function testCanonicalUrlRejectsControlCharacters(): void
    {
        putenv("APP_URL=https://example.com\x00");

        try {
            getCanonicalAppUrl();
            $this->fail('URL with control characters should throw');
        } catch (\Exception $e) {
            $this->assertStringContainsString('control', strtolower($e->getMessage()));
        }
    }

    public function testCanonicalUrlDoesNotTrustHeaders(): void
    {
        putenv('APP_URL=https://legitimate.com');
        $_SERVER['HTTP_HOST'] = 'attacker.com';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'attacker.com';

        $url = getCanonicalAppUrl();
        $this->assertSame('https://legitimate.com', $url, 'Must use APP_URL, not headers');
    }

    public function testCanonicalUrlRequiresHost(): void
    {
        putenv('APP_URL=https://');

        try {
            getCanonicalAppUrl();
            $this->fail('URL without host should throw');
        } catch (\Exception $e) {
            $msg = strtolower($e->getMessage());
            $this->assertTrue(
                stripos($msg, 'host') !== false ||
                stripos($msg, 'invalid') !== false ||
                stripos($msg, 'not') !== false,
                'Error message was: ' . $e->getMessage()
            );
        }
    }

    public function testCanonicalUrlRequiresUrl(): void
    {
        putenv('APP_URL');

        try {
            getCanonicalAppUrl();
            $this->fail('Missing APP_URL should throw');
        } catch (\Exception $e) {
            $this->assertStringContainsString('required', strtolower($e->getMessage()));
        }
    }

    // ========== Helper Methods ==========

    private function validEncryptionKey(): string
    {
        // 64 hex characters = 32 bytes
        return '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    }

    private function differentEncryptionKey(): string
    {
        return 'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210';
    }

    private function validUnsubscribeSecret(): string
    {
        // 32+ bytes
        return 'my-secret-unsubscribe-key-for-32bytes-test-with-plenty-of-entropy-here';
    }
}

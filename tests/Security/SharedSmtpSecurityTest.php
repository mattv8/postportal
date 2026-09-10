<?php

declare(strict_types=1);

namespace PostPortal\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * SharedSmtpSecurityTest verifies SMTP security policies enforced through
 * validateSmtpSecurityPolicy() helper and used by both checkEmailConfiguration()
 * and sendNewPostNotification() functions. Tests include unit tests of helpers
 * and integration tests against running MariaDB in Docker container.
 */
class SharedSmtpSecurityTest extends TestCase
{
    protected static ?\mysqli $db = null;
    protected string|false $originalAppUrl = false;
    protected string|false $originalAppSecret = false;

    protected function setUp(): void
    {
        // Load functions.php to access global functions and helpers
        if (!function_exists('validateSmtpSecurityPolicy')) {
            require_once __DIR__ . '/../../src/functions.php';
        }

        // Save original APP_URL (preserve exact state: false if unset, string if set)
        $this->originalAppUrl = getenv('APP_URL');

        // Save original APP_SECRET if used by tests
        $this->originalAppSecret = getenv('APP_SECRET');
    }

    protected function tearDown(): void
    {
        // Restore APP_URL exactly as it was
        if ($this->originalAppUrl === false) {
            putenv('APP_URL');
        } else {
            putenv('APP_URL=' . $this->originalAppUrl);
        }

        // Restore APP_SECRET if it was saved
        if ($this->originalAppSecret === false) {
            putenv('APP_SECRET');
        } else {
            putenv('APP_SECRET=' . $this->originalAppSecret);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db) {
            @mysqli_close(self::$db);
            self::$db = null;
        }
    }

    /**
     * Create mysqli connection from container environment
     */
    protected function database(): \mysqli
    {
        if (self::$db === null) {
            $host = getenv('MYSQL_HOST') ?: 'localhost';
            $user = getenv('MYSQL_USER') ?: 'postportal';
            $password = getenv('MYSQL_PASSWORD') ?: 'postportal';
            $database = getenv('MYSQL_DATABASE') ?: 'postportal';

            $conn = mysqli_connect($host, $user, $password, $database);
            if (!$conn) {
                $this->fail('Failed to connect to test database: ' . mysqli_connect_error());
            }

            self::$db = $conn;
        }

        return self::$db;
    }

    // ===== UNIT TESTS FOR HELPERS =====

    /**
     * Unit test: validateSmtpSecurityPolicy rejects authenticated plaintext
     */
    public function testValidateSmtpSecurityPolicyRejectsAuthenticatedPlaintext(): void
    {
        // Test unauthenticated connections always allowed
        self::assertTrue(validateSmtpSecurityPolicy(false, 'none'), 'Unauthenticated plaintext allowed');
        self::assertTrue(validateSmtpSecurityPolicy(false, ''), 'Unauthenticated plaintext (empty) allowed');
        self::assertTrue(validateSmtpSecurityPolicy(false, 'tls'), 'Unauthenticated TLS allowed');
        self::assertTrue(validateSmtpSecurityPolicy(false, 'invalid'), 'Unauthenticated any value allowed');

        // Test authenticated MUST use exact 'tls' or 'ssl'
        self::assertFalse(validateSmtpSecurityPolicy(true, 'none'), 'Authenticated plaintext (none) rejected');
        self::assertFalse(validateSmtpSecurityPolicy(true, ''), 'Authenticated plaintext (empty) rejected');
        self::assertFalse(validateSmtpSecurityPolicy(true, 'false'), 'Authenticated false rejected');
        self::assertFalse(validateSmtpSecurityPolicy(true, '0'), 'Authenticated 0 rejected');
    }

    /**
     * Unit test: validateSmtpSecurityPolicy accepts ONLY exact 'tls' and 'ssl'
     */
    public function testValidateSmtpSecurityPolicyAcceptsOnlyExactEncryption(): void
    {
        // Only these exact values allowed for authenticated
        self::assertTrue(validateSmtpSecurityPolicy(true, 'tls'), 'Authenticated lowercase tls accepted');
        self::assertTrue(validateSmtpSecurityPolicy(true, 'ssl'), 'Authenticated lowercase ssl accepted');

        // Reject case variations and constants
        self::assertFalse(validateSmtpSecurityPolicy(true, 'TLS'), 'Authenticated uppercase TLS rejected');
        self::assertFalse(validateSmtpSecurityPolicy(true, 'SSL'), 'Authenticated uppercase SSL rejected');
        self::assertFalse(validateSmtpSecurityPolicy(true, 'Tls'), 'Authenticated mixed case Tls rejected');
        self::assertFalse(validateSmtpSecurityPolicy(true, 'ENCRYPTION_STARTTLS'), 'Authenticated constant name rejected');
        self::assertFalse(validateSmtpSecurityPolicy(true, 'ENCRYPTION_SMTPS'), 'Authenticated constant name rejected');
    }

    /**
     * Unit test: validateSmtpSecurityPolicy rejects whitespace transport values
     */
    public function testValidateSmtpSecurityPolicyRejectsWhitespaceTransport(): void
    {
        self::assertFalse(validateSmtpSecurityPolicy(true, ' tls'), 'Leading whitespace rejected');
        self::assertFalse(validateSmtpSecurityPolicy(true, 'tls '), 'Trailing whitespace rejected');
        self::assertFalse(validateSmtpSecurityPolicy(true, ' tls '), 'Surrounding whitespace rejected');
        self::assertFalse(validateSmtpSecurityPolicy(true, '  '), 'Whitespace-only rejected');
    }

    /**
     * Unit test: SMTP log path is correct
     */
    public function testGetSmtpLogPathReturnsCorrectPath(): void
    {
        self::assertSame('/var/log/postportal/smtp.log', getSmtpLogPath(), 'SMTP log path must be exact');
    }

    /**
     * Unit test: buildNotificationPostUrl uses canonical app URL
     */
    public function testBuildNotificationPostUrlUsesCanonicalAppUrl(): void
    {
        putenv('APP_URL=https://example.com');
        $url = buildNotificationPostUrl(123);
        self::assertSame('https://example.com/?page=home&post_id=123', $url, 'URL format exact match');
    }

    /**
     * Unit test: buildNotificationPostUrl handles invalid APP_URL
     */
    public function testBuildNotificationPostUrlHandlesInvalidAppUrl(): void
    {
        putenv('APP_URL=');
        $url = buildNotificationPostUrl(123);
        self::assertNull($url, 'Returns null when APP_URL missing');
    }

    // ===== INTEGRATION TESTS AGAINST DATABASE =====

    /**
     * Integration test: checkEmailConfiguration rejects authenticated plaintext (testConnection=false)
     *
     * This proves the production blocker fix: policy check is OUTSIDE the if ($testConnection) block.
     */
    public function testCheckEmailConfigurationRejectsAuthenticatedPlaintextWithoutConnection(): void
    {
        $db = $this->database();
        mysqli_begin_transaction($db);

        try {
            // Configure settings with enabled notifications, all required fields, auth enabled, insecure transport
            $this->updateSettingsForTest($db, [
                'notify_subscribers_on_post' => 1,
                'smtp_host' => 'marker_integration_host',
                'smtp_port' => 587,
                'smtp_secure' => 'none', // INSECURE: plaintext with auth
                'smtp_auth' => 1,
                'smtp_username' => 'marker_test_user',
                'smtp_password' => 'marker_test_secret',
                'smtp_from_email' => 'test@example.com',
                'smtp_from_name' => 'Test Sender'
            ]);

            // Call checkEmailConfiguration with testConnection=false
            // Policy check should happen BEFORE the if (testConnection) block, so this should reject
            $result = checkEmailConfiguration($db, false);

            // Verify rejection
            self::assertFalse($result['canSend'], 'Should reject authenticated plaintext even with testConnection=false');
            self::assertArrayHasKey('error', $result, 'Should have error message');
            self::assertArrayHasKey('actionRequired', $result, 'Should have actionRequired');
            self::assertEquals('configure_smtp', $result['actionRequired'], 'Should be configure_smtp action');

            // Verify no internal markers leaked to client
            $serialized = json_encode($result);
            self::assertStringNotContainsString('marker_integration_host', $serialized, 'Host marker not in result');
            self::assertStringNotContainsString('marker_test_user', $serialized, 'Username marker not in result');
            self::assertStringNotContainsString('marker_test_secret', $serialized, 'Password marker not in result');
            self::assertStringNotContainsString('marker_', $serialized, 'No markers in serialized result');
        } finally {
            mysqli_rollback($db);
        }
    }

    /**
     * Integration test: checkEmailConfiguration accepts authenticated with TLS (no network)
     */
    public function testCheckEmailConfigurationAcceptsAuthenticatedWithTls(): void
    {
        $db = $this->database();
        mysqli_begin_transaction($db);

        try {
            $this->updateSettingsForTest($db, [
                'notify_subscribers_on_post' => 1,
                'smtp_host' => 'smtp.example.com',
                'smtp_port' => 587,
                'smtp_secure' => 'tls',
                'smtp_auth' => 1,
                'smtp_username' => 'user@example.com',
                'smtp_password' => 'password123',
                'smtp_from_email' => 'test@example.com',
                'smtp_from_name' => 'Test Sender'
            ]);

            $result = checkEmailConfiguration($db, false);

            // Should pass policy check (testConnection=false means no actual SMTP connection)
            self::assertTrue($result['canSend'], 'Should accept authenticated TLS (no connection test)');
            self::assertArrayNotHasKey('error', $result, 'Should have no error for passing policy');
        } finally {
            mysqli_rollback($db);
        }
    }

    /**
     * Integration test: sendNewPostNotification rejects authenticated plaintext BEFORE post lookup
     *
     * Uses nonexistent post ID to prove rejection happens before database queries.
     */
    public function testSendNewPostNotificationRejectsAuthenticatedPlaintextEarly(): void
    {
        $db = $this->database();
        mysqli_begin_transaction($db);

        try {
            $this->updateSettingsForTest($db, [
                'notify_subscribers_on_post' => 1,
                'smtp_host' => 'marker_send_host',
                'smtp_port' => 587,
                'smtp_secure' => 'none', // INSECURE plaintext with auth
                'smtp_auth' => 1,
                'smtp_username' => 'marker_send_user',
                'smtp_password' => 'marker_send_secret',
                'smtp_from_email' => 'test@example.com',
                'smtp_from_name' => 'Test'
            ]);

            // Use nonexistent post ID (positive but doesn't exist in test transaction)
            $nonexistentPostId = 999999;

            // Call sendNewPostNotification - should reject before post lookup
            $result = sendNewPostNotification($db, $nonexistentPostId);

            // Verify rejection
            self::assertFalse($result['success'], 'Should reject authenticated plaintext');
            self::assertArrayHasKey('error', $result, 'Should have error message');
            self::assertArrayHasKey('actionRequired', $result, 'Should have actionRequired');

            // Verify no internal markers leaked to client
            $serialized = json_encode($result);
            self::assertStringNotContainsString('marker_send_host', $serialized, 'Host marker not in result');
            self::assertStringNotContainsString('marker_send_user', $serialized, 'Username marker not in result');
            self::assertStringNotContainsString('marker_send_secret', $serialized, 'Password marker not in result');
            self::assertStringNotContainsString('marker_', $serialized, 'No markers in serialized result');
        } finally {
            mysqli_rollback($db);
        }
    }

    /**
     * Update settings row (id=1) with fixed columns using prepared statement
     *
     * @param \mysqli $db Database connection
     * @param array<string,int|string> $values Key-value pairs for columns
     */
    private function updateSettingsForTest(\mysqli $db, array $values): void
    {
        if (!empty($values['smtp_password'])) {
            putenv('APP_SECRET=' . str_repeat('a1', 32));
            $values['smtp_password'] = encryptSmtpPassword((string)$values['smtp_password']);
        }

        // Build fixed list of supported columns for prepared statement
        $allowed = [
            'notify_subscribers_on_post',
            'smtp_host',
            'smtp_port',
            'smtp_secure',
            'smtp_auth',
            'smtp_username',
            'smtp_password',
            'smtp_from_email',
            'smtp_from_name',
        ];

        $sets = [];
        $types = '';
        $params = [];

        foreach ($values as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                $this->fail("Unsupported settings column: {$key}");
            }

            $sets[] = "`{$key}` = ?";
            if (is_int($value)) {
                $types .= 'i';
            } else {
                $types .= 's';
            }
            $params[] = $value;
        }

        if (empty($sets)) {
            return;
        }

        // Always update id=1 only
        $sql = 'UPDATE settings SET ' . implode(', ', $sets) . ' WHERE id = 1';
        $stmt = mysqli_prepare($db, $sql);

        if (!$stmt) {
            $this->fail('Failed to prepare update: ' . mysqli_error($db));
        }

        mysqli_stmt_bind_param($stmt, $types, ...$params);

        if (!mysqli_stmt_execute($stmt)) {
            $this->fail('Failed to update settings: ' . mysqli_error($db));
        }

        $affected = mysqli_stmt_affected_rows($stmt);
        if ($affected <= 0) {
            $this->fail('No settings row found to update');
        }

        mysqli_stmt_close($stmt);
    }
}

<?php

declare(strict_types=1);

namespace PostPortal\Tests\Security;

use PostPortal\Repository\NewsletterThrottleRepository;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/functions.php';

final class NewsletterDoubleOptInTest extends TestCase
{
    private \mysqli $db;
    /** @var list<string> */
    private array $sourceKeys = [];
    private string|false $originalAppKey;

    protected function setUp(): void
    {
        $db = getDefaultDbConnection();
        if (!$db instanceof \mysqli) {
            throw new \RuntimeException('Newsletter throttle tests require the configured MySQL environment.');
        }
        $this->db = $db;
        $this->originalAppKey = getenv('APP_ENCRYPTION_KEY');
        putenv('APP_ENCRYPTION_KEY=' . bin2hex(random_bytes(32)));
    }

    protected function tearDown(): void
    {
        foreach ($this->sourceKeys as $sourceKey) {
            $statement = $this->db->prepare('DELETE FROM newsletter_throttle WHERE source_key = ?');
            if ($statement !== false) {
                $statement->bind_param('s', $sourceKey);
                $statement->execute();
            }
        }
        if ($this->originalAppKey === false) {
            putenv('APP_ENCRYPTION_KEY');
        } else {
            putenv('APP_ENCRYPTION_KEY=' . $this->originalAppKey);
        }
        $this->db->close();
    }

    public function testFirstFiveRequestsAreAllowedAndSixthIsBlocked(): void
    {
        $repository = new NewsletterThrottleRepository($this->db);
        $sourceKey = $this->sourceKey('198.51.100.10');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            self::assertFalse($repository->isThrottled($sourceKey), "Attempt {$attempt} must be allowed");
        }
        self::assertTrue($repository->isThrottled($sourceKey));
        self::assertTrue($repository->isThrottled($sourceKey));
    }

    public function testExpiredWindowResetsToOneAllowedRequest(): void
    {
        $repository = new NewsletterThrottleRepository($this->db);
        $sourceKey = $this->sourceKey('198.51.100.11');
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $repository->isThrottled($sourceKey);
        }

        $statement = $this->db->prepare('UPDATE newsletter_throttle SET request_count = 9999, window_start = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 HOUR) WHERE source_key = ?');
        self::assertNotFalse($statement);
        $statement->bind_param('s', $sourceKey);
        $statement->execute();

        self::assertFalse($repository->isThrottled($sourceKey));
        $statement = $this->db->prepare('SELECT request_count FROM newsletter_throttle WHERE source_key = ?');
        self::assertNotFalse($statement);
        $statement->bind_param('s', $sourceKey);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        self::assertSame('1', (string) $row['request_count']);
    }

    public function testMissingOrInvalidApplicationKeyFailsClosed(): void
    {
        putenv('APP_ENCRYPTION_KEY');
        try {
            NewsletterThrottleRepository::deriveSourceKey('198.51.100.12');
            self::fail('Missing APP_ENCRYPTION_KEY must fail closed.');
        } catch (\RuntimeException) {
            self::addToAssertionCount(1);
        }

        putenv('APP_ENCRYPTION_KEY=not-a-64-hex-key');
        try {
            NewsletterThrottleRepository::deriveSourceKey('198.51.100.13');
            self::fail('An invalid APP_ENCRYPTION_KEY must fail closed.');
        } catch (\RuntimeException) {
            self::addToAssertionCount(1);
        }
    }

    public function testSourceKeyIsPurposeSeparatedHmacAndDoesNotContainIp(): void
    {
        $ip = '198.51.100.14';
        $sourceKey = $this->sourceKey($ip);

        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $sourceKey);
        self::assertStringNotContainsString($ip, $sourceKey);
        self::assertNotSame(
            hash_hmac('sha256', $ip, hex2bin((string) getenv('APP_ENCRYPTION_KEY'))),
            $sourceKey,
            'The purpose prefix must isolate throttle HMACs from other APP_ENCRYPTION_KEY uses.'
        );
    }

    private function sourceKey(string $ip): string
    {
        $sourceKey = NewsletterThrottleRepository::deriveSourceKey($ip);
        $this->sourceKeys[] = $sourceKey;
        return $sourceKey;
    }
}

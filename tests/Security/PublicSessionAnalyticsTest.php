<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PostPortal\Http\SessionPolicy;

require_once __DIR__ . '/../../src/Http/SessionPolicy.php';

final class PublicSessionAnalyticsTest extends TestCase
{
    private string $sessionDirectory;

    protected function setUp(): void
    {
        $this->sessionDirectory = sys_get_temp_dir() . '/public-api-session-' . bin2hex(random_bytes(8));
        mkdir($this->sessionDirectory, 0700, true);
        session_save_path($this->sessionDirectory);
        session_id('');
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $_COOKIE = [];
        array_map('unlink', glob($this->sessionDirectory . '/*') ?: []);
        rmdir($this->sessionDirectory);
    }

    public function testCookieLessPublicRequestDoesNotStartSession(): void
    {
        $_COOKIE = [];

        self::assertFalse(SessionPolicy::shouldStartSession(false));
        SessionPolicy::apply();
        SessionPolicy::startSessionIfNeeded(false);

        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertSame([], glob($this->sessionDirectory . '/sess_*') ?: []);
    }

    public function testAuthenticatedCookieMakesOptionalSessionAvailable(): void
    {
        $sessionId = 'existing' . bin2hex(random_bytes(16));
        session_id($sessionId);
        session_start();
        $_SESSION['authenticated'] = true;
        $_SESSION['isadmin'] = true;
        session_write_close();
        $_COOKIE = [session_name() => $sessionId];

        self::assertTrue(SessionPolicy::shouldStartSession(false));
        SessionPolicy::apply();
        SessionPolicy::startSessionIfNeeded(false);

        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        self::assertTrue($_SESSION['authenticated']);
        self::assertTrue($_SESSION['isadmin']);
    }

    public function testAnalyticsCanRemainStatelessEvenWhenAVisitorHasACookie(): void
    {
        $_COOKIE = [session_name() => 'existing-session'];

        self::assertFalse(SessionPolicy::shouldStartSession(false, false));
    }

    public function testAuthRequirementAlwaysStartsSession(): void
    {
        $_COOKIE = [];

        self::assertTrue(SessionPolicy::shouldStartSession(true));
    }

}

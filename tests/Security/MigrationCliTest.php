<?php

declare(strict_types=1);

namespace PostPortal\Tests\Security;

use PHPUnit\Framework\TestCase;

final class MigrationCliTest extends TestCase
{
    private string $cliPath;

    protected function setUp(): void
    {
        $this->cliPath = dirname(__DIR__, 2) . '/src/Lib/migrate-smtp-password-cli.php';
    }

    public function testMissingAppSecretReturnsGenericConfigurationError(): void
    {
        [$status, , $stderr] = $this->runCli([]);
        self::assertSame(1, $status);
        self::assertSame("Configuration error\n", $stderr);
        self::assertStringNotContainsString('APP_SECRET', $stderr);
    }

    public function testMalformedAppSecretReturnsGenericConfigurationError(): void
    {
        [$status, , $stderr] = $this->runCli(['APP_SECRET' => 'not-hex']);
        self::assertSame(1, $status);
        self::assertSame("Configuration error\n", $stderr);
    }

    public function testValidAppSecretReachesDatabaseValidationWithoutLeakingSecrets(): void
    {
        $secret = str_repeat('a', 64);
        [$status, $stdout, $stderr] = $this->runCli([
            'APP_SECRET' => $secret,
            'MYSQL_HOST' => '127.0.0.1',
            'MYSQL_USER' => 'user',
            'MYSQL_PASSWORD' => 'password',
            'MYSQL_DATABASE' => 'database',
        ]);
        self::assertSame(2, $status);
        self::assertSame('', $stdout);
        self::assertSame("Database error\n", $stderr);
        self::assertStringNotContainsString($secret, $stderr);
    }

    public function testRequiringCliIsIncludeSafe(): void
    {
        [$status, $stdout, $stderr] = $this->runCode(
            'require_once ' . var_export($this->cliPath, true) . "; echo 'CALLER_MARKER';"
        );

        self::assertSame(0, $status);
        self::assertSame('CALLER_MARKER', $stdout);
        self::assertSame('', $stderr);
    }

    public function testIsolatedCliWithoutAutoloadReturnsGenericDatabaseError(): void
    {
        $tempDir = sys_get_temp_dir() . '/postportal-cli-' . bin2hex(random_bytes(8));
        $tempCliPath = $tempDir . '/migrate-smtp-password-cli.php';
        self::assertTrue(mkdir($tempDir, 0700));

        try {
            self::assertTrue(copy($this->cliPath, $tempCliPath));
            [$status, $stdout, $stderr] = $this->runPath($tempCliPath, [
                'APP_SECRET' => str_repeat('a', 64),
                'MYSQL_HOST' => 'localhost',
                'MYSQL_USER' => 'user',
                'MYSQL_PASSWORD' => 'password',
                'MYSQL_DATABASE' => 'database',
            ]);

            self::assertSame(2, $status);
            self::assertSame('', $stdout);
            self::assertSame("Database error\n", $stderr);
            self::assertStringNotContainsString('vendor', $stderr);
            self::assertStringNotContainsString($tempDir, $stderr);
            self::assertStringNotContainsString('Exception', $stderr);
        } finally {
            if (file_exists($tempCliPath)) {
                unlink($tempCliPath);
            }
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    }

    private function runCli(array $environment): array
    {
        return $this->runPath($this->cliPath, $environment);
    }

    private function runPath(string $path, array $environment): array
    {
        $process = proc_open(
            'php ' . escapeshellarg($path),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'] + $environment
        );
        self::assertNotFalse($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $stdout, $stderr];
    }

    private function runCode(string $code): array
    {
        $process = proc_open(
            'php -r ' . escapeshellarg($code),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']
        );
        self::assertNotFalse($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $stdout, $stderr];
    }
}

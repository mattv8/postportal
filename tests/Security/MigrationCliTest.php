<?php

declare(strict_types=1);

namespace PostPortal\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Behavioral tests for CLI autoload contract and direct execution.
 * Uses subprocess execution to verify real CLI behavior.
 */
class MigrationCliTest extends TestCase
{
    private string $repoRoot;
    private string $cliPath;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        // In Docker, src is mounted directly; otherwise use src/Lib path
        $dockerPath = '/var/www/html/Lib/migrate-smtp-password-cli.php';
        $repoPath = $this->repoRoot . '/src/Lib/migrate-smtp-password-cli.php';
        $this->cliPath = file_exists($dockerPath) ? $dockerPath : $repoPath;
    }

    /**
     * Test requiring CLI prints only a caller marker and exits 0.
     */
    public function testRequiringCliPrintsCallerMarkerExitsZero(): void
    {
        $output = $this->runPhpCode(
            "require_once '{$this->cliPath}'; echo 'OK';"
        );

        $this->assertSame(0, $this->lastExitCode, 'Requiring CLI exits 0');
        $this->assertSame('OK', $output, 'Caller code executes and prints OK');
        $this->assertEmpty($this->lastStderr, 'No error output when requiring');
    }

    /**
     * Test direct run with missing APP_ENCRYPTION_KEY exits 1.
     */
    public function testDirectRunMissingAppKeyExitsOne(): void
    {
        $this->runPhpDirect(
            "php '{$this->cliPath}'",
            []  // No APP_ENCRYPTION_KEY
        );

        $this->assertSame(1, $this->lastExitCode, 'Missing APP key exits 1');
        $stderr = $this->lastStderr;
        $this->assertNotEmpty($stderr, 'Error message sent to STDERR');
        $this->assertStringNotContainsString('APP_ENCRYPTION_KEY', $stderr, 'No key name in output');
    }

    /**
     * Test direct run with malformed APP_ENCRYPTION_KEY exits 1.
     */
    public function testDirectRunMalformedAppKeyExitsOne(): void
    {
        $this->runPhpDirect(
            "php '{$this->cliPath}'",
            ['APP_ENCRYPTION_KEY' => 'not-hex-or-wrong-length']
        );

        $this->assertSame(1, $this->lastExitCode, 'Malformed APP key exits 1');
        $stderr = $this->lastStderr;
        $this->assertNotEmpty($stderr, 'Error message sent to STDERR');
    }

    /**
     * Test direct run with missing MYSQL_HOST exits 1.
     */
    public function testDirectRunMissingMysqlHostExitsOne(): void
    {
        $validKey = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
        $this->runPhpDirect(
            "php '{$this->cliPath}'",
            ['APP_ENCRYPTION_KEY' => $validKey]  // Missing MYSQL_HOST
        );

        $this->assertSame(1, $this->lastExitCode, 'Missing MYSQL_HOST exits 1');
    }

    /**
     * Test direct run with missing MYSQL_USER exits 1.
     */
    public function testDirectRunMissingMysqlUserExitsOne(): void
    {
        $validKey = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
        $this->runPhpDirect(
            "php '{$this->cliPath}'",
            [
                'APP_ENCRYPTION_KEY' => $validKey,
                'MYSQL_HOST' => 'localhost',
                // Missing MYSQL_USER
            ]
        );

        $this->assertSame(1, $this->lastExitCode, 'Missing MYSQL_USER exits 1');
    }

    /**
     * Test direct run with missing MYSQL_PASSWORD exits 1.
     */
    public function testDirectRunMissingMysqlPasswordExitsOne(): void
    {
        $validKey = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
        $this->runPhpDirect(
            "php '{$this->cliPath}'",
            [
                'APP_ENCRYPTION_KEY' => $validKey,
                'MYSQL_HOST' => 'localhost',
                'MYSQL_USER' => 'user',
                // Missing MYSQL_PASSWORD
            ]
        );

        $this->assertSame(1, $this->lastExitCode, 'Missing MYSQL_PASSWORD exits 1');
    }

    /**
     * Test direct run with missing MYSQL_DATABASE exits 1.
     */
    public function testDirectRunMissingMysqlDatabaseExitsOne(): void
    {
        $validKey = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
        $this->runPhpDirect(
            "php '{$this->cliPath}'",
            [
                'APP_ENCRYPTION_KEY' => $validKey,
                'MYSQL_HOST' => 'localhost',
                'MYSQL_USER' => 'user',
                'MYSQL_PASSWORD' => 'pass',
                // Missing MYSQL_DATABASE
            ]
        );

        $this->assertSame(1, $this->lastExitCode, 'Missing MYSQL_DATABASE exits 1');
    }

    /**
     * Test direct run with valid config but unavailable database exits 2 promptly.
     */
    public function testDirectRunValidConfigUnavailableDbExitsTwo(): void
    {
        $validKey = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

        // Use loopback address that will refuse connection promptly
        $startTime = microtime(true);
        $this->runPhpDirect(
            "php '{$this->cliPath}'",
            [
                'APP_ENCRYPTION_KEY' => $validKey,
                'MYSQL_HOST' => '127.0.0.1',  // Loopback will refuse
                'MYSQL_USER' => 'user',
                'MYSQL_PASSWORD' => 'pass',
                'MYSQL_DATABASE' => 'db',
            ],
            timeout: 5  // 5 second subprocess timeout
        );
        $elapsed = microtime(true) - $startTime;

        $this->assertSame(2, $this->lastExitCode, 'DB connection error exits 2');
        $this->assertLessThan(4, $elapsed, 'Connection fails promptly (< 4s)');
    }

    /**
     * Test that outputs contain no supplied secrets.
     */
    public function testOutputsContainNoSecrets(): void
    {
        $validKey = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
        $host = '127.0.0.1';
        $user = 'testuser123';
        $password = 'testpass456';
        $database = 'testdb789';

        $this->runPhpDirect(
            "php '{$this->cliPath}'",
            [
                'APP_ENCRYPTION_KEY' => $validKey,
                'MYSQL_HOST' => $host,
                'MYSQL_USER' => $user,
                'MYSQL_PASSWORD' => $password,
                'MYSQL_DATABASE' => $database,
            ],
            timeout: 5
        );

        $output = $this->lastStdout . $this->lastStderr;

        $this->assertStringNotContainsString($validKey, $output, 'No APP_ENCRYPTION_KEY in output');
        $this->assertStringNotContainsString($host, $output, 'No MYSQL_HOST in output');
        $this->assertStringNotContainsString($user, $output, 'No MYSQL_USER in output');
        $this->assertStringNotContainsString($password, $output, 'No MYSQL_PASSWORD in output');
        $this->assertStringNotContainsString($database, $output, 'No MYSQL_DATABASE in output');
    }

    /**
     * Test that outputs contain no internal details.
     */
    public function testOutputsContainNoInternals(): void
    {
        $validKey = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

        $this->runPhpDirect(
            "php '{$this->cliPath}'",
            [
                'APP_ENCRYPTION_KEY' => $validKey,
                'MYSQL_HOST' => '127.0.0.1',
                'MYSQL_USER' => 'user',
                'MYSQL_PASSWORD' => 'pass',
                'MYSQL_DATABASE' => 'db',
            ],
            timeout: 5
        );

        $output = $this->lastStdout . $this->lastStderr;

        $this->assertStringNotContainsString('mysqli', $output, 'No mysqli details');
        $this->assertStringNotContainsString('Exception', $output, 'No exception class names');
        $this->assertStringNotContainsString('/src/', $output, 'No filesystem paths');
        $this->assertStringNotContainsString('\\', $output, 'No backslash paths');
        $this->assertStringNotContainsString('Stack', $output, 'No stack trace markers');
    }

    /**
     * Test requiring functions.php after root Composer bootstrap defines helpers.
     */
    public function testRequiringFunctionsAfterBootstrapDefinesHelpers(): void
    {
        // In Docker, src is mounted directly as functions.php; otherwise in repo
        $dockerFunctionsPath = '/var/www/html/functions.php';
        $repoFunctionsPath = $this->repoRoot . '/src/functions.php';
        $functionsPath = file_exists($dockerFunctionsPath) ? $dockerFunctionsPath : $repoFunctionsPath;

        $output = $this->runPhpCode(
            "
require_once '{$this->repoRoot}/vendor/autoload.php';
require_once '{$functionsPath}';
if (function_exists('getDbConnection') && function_exists('getAppDefaults')) {
    echo 'Functions defined';
}
"
        );

        $this->assertSame(0, $this->lastExitCode, 'Functions require succeeds');
        $this->assertStringContainsString('Functions defined', $output, 'Helper functions exist');
    }

    /**
     * Test direct run with no Composer autoload candidate exits 2.
     * Creates isolated temp copy of CLI without access to autoload, runs it, cleans up.
     */
    public function testDirectRunNoAutoloadExitsTwo(): void
    {
        // Create temporary directory for test
        $tempDir = sys_get_temp_dir() . '/' . uniqid('cli_test_', true);
        mkdir($tempDir);

        try {
            // Copy CLI to temp location
            $tempCliPath = $tempDir . '/migrate-smtp-password-cli.php';
            copy($this->cliPath, $tempCliPath);

            $validKey = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

            $this->runPhpDirect(
                "php '{$tempCliPath}'",
                [
                    'APP_ENCRYPTION_KEY' => $validKey,
                    'MYSQL_HOST' => 'localhost',
                    'MYSQL_USER' => 'user',
                    'MYSQL_PASSWORD' => 'pass',
                    'MYSQL_DATABASE' => 'db',
                ],
                timeout: 5
            );

            $this->assertSame(2, $this->lastExitCode, 'No autoload exits 2');
            $stderr = $this->lastStderr;
            $this->assertStringContainsString('Database error', $stderr, 'Generic error message');
            $this->assertStringNotContainsString('vendor', $stderr, 'No path details in error');
        } finally {
            // Clean up temp files
            if (file_exists($tempCliPath)) {
                unlink($tempCliPath);
            }
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    }

    // ========== Helpers ==========

    private int $lastExitCode = 0;
    private string $lastStdout = '';
    private string $lastStderr = '';

    /**
     * Run PHP code in subprocess (for requiring).
     */
    private function runPhpCode(string $code): string
    {
        $process = proc_open(
            'php -r ' . escapeshellarg($code),
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if ($process === false) {
            $this->fail('Failed to open process');
        }

        $this->lastStdout = stream_get_contents($pipes[1]);
        $this->lastStderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->lastExitCode = proc_close($process);
        return $this->lastStdout;
    }

    /**
     * Run PHP CLI directly with environment variables.
     * Deterministic environment with only PATH and explicit test variables.
     * Uses non-blocking I/O with stream_select for deadline enforcement.
     */
    private function runPhpDirect(
        string $command,
        array $env = [],
        int $timeout = 5
    ): void {
        // Build deterministic environment: only PATH + explicit test vars
        $fullEnv = ['PATH' => $_ENV['PATH'] ?? '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'];
        foreach ($env as $key => $value) {
            $fullEnv[$key] = $value;
        }

        $process = proc_open(
            $command,
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->repoRoot,
            $fullEnv
        );

        if ($process === false) {
            $this->fail('Failed to open process');
        }

        // Set pipes to non-blocking for stream_select
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + $timeout;
        $stdout = '';
        $stderr = '';
        $exitCode = -1;

        while (microtime(true) < $deadline) {
            // Check if process is still running; capture exitcode immediately when done
            $status = proc_get_status($process);
            if (!($status['running'] ?? true)) {
                $exitCode = (int)($status['exitcode'] ?? -1);
            }

            // Use stream_select for polling readable pipes with remaining deadline
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            $remainingTime = $deadline - microtime(true);
            if ($remainingTime <= 0) {
                break;
            }

            $selectTimeout = max(0, (int)$remainingTime);
            $selectUsec = max(0, (int)(($remainingTime - $selectTimeout) * 1000000));
            $selectedCount = @stream_select($read, $write, $except, $selectTimeout, $selectUsec);

            if ($selectedCount === false) {
                break;
            }

            if ($selectedCount > 0) {
                foreach ($read as $pipe) {
                    $data = stream_get_contents($pipe);
                    if ($data !== false) {
                        if ($pipe === $pipes[1]) {
                            $stdout .= $data;
                        } else {
                            $stderr .= $data;
                        }
                    }
                }
            }

            if ($exitCode !== -1) {
                // Process completed, read any remaining data and exit
                break;
            }
        }

        // Drain any remaining output
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        // Check for timeout
        $status = proc_get_status($process);
        if ($status['running'] ?? false) {
            proc_terminate($process, 9);
            proc_close($process);
            $this->fail("Process exceeded {$timeout}s timeout");
        }

        if ($exitCode === -1) {
            $exitCode = (int)($status['exitcode'] ?? -1);
        }

        $this->lastExitCode = $exitCode;
        $this->lastStdout = $stdout;
        $this->lastStderr = $stderr;

        proc_close($process);
    }
}

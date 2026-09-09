<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PostPortal\Repository\UserRepository;
use PostPortal\Security\HmacPrivacyKey;
use PostPortal\Service\UserService;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/functions.php';
require_once __DIR__ . '/../../src/Repository/BaseRepository.php';
require_once __DIR__ . '/../../src/Repository/UserRepositoryInterface.php';
require_once __DIR__ . '/../../src/Repository/UserRepository.php';
require_once __DIR__ . '/../../src/Service/UserServiceInterface.php';
require_once __DIR__ . '/../../src/Service/UserService.php';
require_once __DIR__ . '/../../src/Security/HmacPrivacyKey.php';

final class AuthSecurityTest extends TestCase
{
    private static string $runId;
    private static string $copy;
    private static string $sessions;
    private static int $port;
    private static ?int $serverPid = null;
    private static mysqli $db;
    private static string $appKey;

    protected function setUp(): void
    {
        self::bootIsolatedApplication();
        self::cleanDatabase();
    }

    public static function setUpBeforeClass(): void
    {
        self::$runId = 't2_' . bin2hex(random_bytes(4));
        self::$appKey = bin2hex(random_bytes(32));
        putenv('APP_ENCRYPTION_KEY=' . self::$appKey);
        self::$db = self::database();
        self::bootIsolatedApplication();
    }

    public static function tearDownAfterClass(): void
    {
        self::cleanDatabase();
        if (self::$serverPid !== null) {
            @posix_kill(-self::$serverPid, SIGTERM);
            @pcntl_waitpid(self::$serverPid, $status, WNOHANG);
        }
        self::removeTree(self::$copy);
        self::$db->close();
    }

    public function testUnknownSessionIdIsReplacedUnderStrictMode(): void
    {
        $unknown = 'unknown' . bin2hex(random_bytes(20));
        $response = self::request('/api/login.php', ['username' => self::$runId . '_missing', 'password' => 'wrong9'], $unknown);

        self::assertSame(401, $response['status']);
        $replacement = self::sessionIdFromHeaders($response['headers']);
        self::assertNotSame($unknown, $replacement);
        self::assertFileExists(self::$sessions . '/sess_' . $replacement);
    }

    public function testSuccessfulLoginRotatesSessionAndDeletesOldFile(): void
    {
        $username = self::$runId . '_login';
        self::createUser($username, 'LoginPassword9', 1);
        $oldId = 'known' . bin2hex(random_bytes(20));
        file_put_contents(self::$sessions . '/sess_' . $oldId, 'seed|s:1:"x";');

        $response = self::request('/api/login.php', ['username' => $username, 'password' => 'LoginPassword9'], $oldId);
        self::assertSame(200, $response['status']);
        $newId = self::sessionIdFromHeaders($response['headers']);
        self::assertNotSame($oldId, $newId);
        self::assertFileDoesNotExist(self::$sessions . '/sess_' . $oldId);
        self::assertFileExists(self::$sessions . '/sess_' . $newId);
    }

    public function testInvalidLogoutReturns419AndPreservesSession(): void
    {
        $username = self::$runId . '_logout';
        self::createUser($username, 'LogoutPassword9', 1);
        $login = self::request('/api/login.php', ['username' => $username, 'password' => 'LogoutPassword9']);
        $sessionId = self::sessionIdFromHeaders($login['headers']);

        $logout = self::request('/api/logout.php', [], $sessionId);
        self::assertSame(419, $logout['status']);
        $contents = file_get_contents(self::$sessions . '/sess_' . $sessionId);
        self::assertIsString($contents);
        self::assertStringContainsString('authenticated', $contents);
    }

    public function testInactiveUserLoginIsRejected(): void
    {
        $username = self::$runId . '_inactive';
        self::createUser($username, 'InactivePassword9', 0);
        $response = self::request('/api/login.php', ['username' => $username, 'password' => 'InactivePassword9']);
        self::assertSame(401, $response['status']);
    }

    public function testActivationRejectsMalformedWrongPurposeExpiredAndReusedTokens(): void
    {
        $username = self::$runId . '_activate';
        self::createUser($username, 'UnusablePassword9', 0);
        $base = ['username' => $username, 'password' => 'ActivatePassword9', 'password_confirm' => 'ActivatePassword9'];

        self::assertSame(400, self::request('/api/auth/activate.php', $base + ['token' => 'bad'])['status']);
        self::insertToken($username, bin2hex(random_bytes(32)), 'reset_password', '+1 day');
        self::assertSame(401, self::request('/api/auth/activate.php', $base + ['token' => self::$lastToken])['status']);
        self::insertToken($username, bin2hex(random_bytes(32)), 'activate', '-1 second');
        self::assertSame(401, self::request('/api/auth/activate.php', $base + ['token' => self::$lastToken])['status']);

        $issued = (new UserService(new UserRepository(self::$db)))->issueActivationToken($username);
        self::assertTrue($issued['success']);
        $payload = $base + ['token' => $issued['token']];
        self::assertSame(200, self::request('/api/auth/activate.php', $payload)['status']);
        self::assertSame(401, self::request('/api/auth/activate.php', $payload)['status']);
    }

    public function testConcurrentActivationHasExactlyOneWinner(): void
    {
        $username = self::$runId . '_concurrent';
        self::createUser($username, 'UnusablePassword9', 0);
        $issued = (new UserService(new UserRepository(self::$db)))->issueActivationToken($username);
        self::assertTrue($issued['success']);
        $payload = json_encode(['username' => $username, 'token' => $issued['token'], 'password' => 'ConcurrentPassword9', 'password_confirm' => 'ConcurrentPassword9'], JSON_THROW_ON_ERROR);
        $command = self::curlCommand('/api/auth/activate.php', $payload, null, '127.0.0.1');
        $processes = [self::startProcess($command), self::startProcess($command)];
        $statuses = [];
        foreach ($processes as $process) {
            $output = stream_get_contents($process['pipes'][1]);
            fclose($process['pipes'][1]);
            fclose($process['pipes'][2]);
            proc_close($process['process']);
            preg_match('/HTTP_CODE:(\d+)/', $output, $match);
            $statuses[] = (int) ($match[1] ?? 0);
        }
        sort($statuses);
        self::assertSame([200, 401], $statuses);
    }

    public function testSourceAndUsernameThrottleIndependentlyAllowFiveThenRejectSixth(): void
    {
        self::clearSourceThrottle('127.0.0.1');
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(401, self::request('/api/login.php', ['username' => self::$runId . '_source_' . $i, 'password' => 'wrong9'], null, '127.0.0.1')['status']);
        }
        self::assertSame(429, self::request('/api/login.php', ['username' => self::$runId . '_source_six', 'password' => 'wrong9'], null, '127.0.0.1')['status']);
        self::clearSourceThrottle('127.0.0.1');

        $username = self::$runId . '_username';
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(401, self::request('/api/login.php', ['username' => $username, 'password' => 'wrong9'])['status']);
            self::clearSourceThrottle('127.0.0.1');
        }
        self::assertSame(429, self::request('/api/login.php', ['username' => $username, 'password' => 'wrong9'])['status']);
    }

    public function testExpiredThrottleRecordsResetToOneForBothScopes(): void
    {
        $source = self::$runId . '_expired_source_' . bin2hex(random_bytes(4));
        $username = self::$runId . '_expired_username_' . bin2hex(random_bytes(4));
        $sourceHmac = HmacPrivacyKey::generateThrottleKey($source);
        $usernameHmac = HmacPrivacyKey::generateThrottleKey($username);

        try {
            self::insertExpiredThrottleRecord('login_attempts_by_source', 'source_hmac', $sourceHmac);
            self::insertExpiredThrottleRecord('login_attempts_by_username', 'username_hmac', $usernameHmac);

            self::assertTrue((new UserRepository(self::$db))->recordLoginAttempt($source, $username));
            self::assertSame(1, self::throttleAttemptCount('login_attempts_by_source', 'source_hmac', $sourceHmac));
            self::assertSame(1, self::throttleAttemptCount('login_attempts_by_username', 'username_hmac', $usernameHmac));
        } finally {
            self::deleteThrottleRecord('login_attempts_by_source', 'source_hmac', $sourceHmac);
            self::deleteThrottleRecord('login_attempts_by_username', 'username_hmac', $usernameHmac);
        }
    }

    public function testMissingEncryptionKeyFailsClosedForThrottleCheck(): void
    {
        $originalKey = getenv('APP_ENCRYPTION_KEY');
        putenv('APP_ENCRYPTION_KEY');

        try {
            $result = (new UserRepository(self::$db))->checkLoginThrottle('source-' . bin2hex(random_bytes(4)), 'username-' . bin2hex(random_bytes(4)));
            self::assertFalse($result['allowed']);
            self::assertSame(60, $result['retry_after']);
        } finally {
            if ($originalKey === false) {
                putenv('APP_ENCRYPTION_KEY');
            } else {
                putenv('APP_ENCRYPTION_KEY=' . $originalKey);
            }
        }
    }

    private static string $lastToken = '';

    private static function bootIsolatedApplication(): void
    {
        if (isset(self::$copy)) {
            return;
        }
        $root = dirname(__DIR__, 2);
        self::$copy = sys_get_temp_dir() . '/' . self::$runId;
        self::$sessions = self::$copy . '/sessions';
        self::$port = random_int(20000, 40000);
        self::executeCommand('cp -a ' . escapeshellarg($root) . ' ' . escapeshellarg(self::$copy));
        self::executeCommand('rm -rf ' . escapeshellarg(self::$copy . '/src/vendor') . ' && cp -a ' . escapeshellarg($root . '/vendor') . ' ' . escapeshellarg(self::$copy . '/src/vendor'));
        if (!is_dir(self::$copy . '/src/lib') || !is_dir(self::$copy . '/src/Lib')) {
            throw new RuntimeException('Copied source must preserve distinct lib and Lib directories.');
        }
        mkdir(self::$sessions, 0700, true);
        $log = self::$copy . '/server.log';
        $env = 'APP_ENCRYPTION_KEY=' . escapeshellarg(self::$appKey) . ' PHP_CLI_SERVER_WORKERS=4';
        $command = 'setsid sh -c ' . escapeshellarg($env . ' MYSQL_HOST=' . escapeshellarg((string) getenv('MYSQL_HOST')) . ' MYSQL_DATABASE=' . escapeshellarg((string) getenv('MYSQL_DATABASE')) . ' MYSQL_USER=' . escapeshellarg((string) getenv('MYSQL_USER')) . ' MYSQL_PASSWORD=' . escapeshellarg((string) getenv('MYSQL_PASSWORD')) . ' exec php -d session.save_path=' . escapeshellarg(self::$sessions) . ' -S 127.0.0.1:' . self::$port . ' -t ' . escapeshellarg(self::$copy . '/src') . ' > ' . escapeshellarg($log) . ' 2>&1') . ' & echo $!';
        self::$serverPid = (int) trim(self::executeCommand($command));
        for ($i = 0; $i < 30; $i++) {
            $socket = @fsockopen('127.0.0.1', self::$port);
            if (is_resource($socket)) {
                fclose($socket);
                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('Isolated PHP server did not start: ' . (string) @file_get_contents($log));
    }

    private static function request(string $path, array $payload, ?string $sessionId = null, string $source = '127.0.0.1'): array
    {
        $output = self::executeCommand(self::curlCommand($path, json_encode($payload, JSON_THROW_ON_ERROR), $sessionId, $source));
        [$headers, $body] = array_pad(preg_split("/\r?\n\r?\n/", $output, 2), 2, '');
        preg_match('/HTTP_CODE:(\d+)/', $body, $match);
        return ['status' => (int) ($match[1] ?? 0), 'headers' => $headers, 'body' => preg_replace('/HTTP_CODE:\d+\s*$/', '', $body)];
    }

    private static function curlCommand(string $path, string $payload, ?string $sessionId, string $source): string
    {
        $cookie = $sessionId === null ? '' : ' -H ' . escapeshellarg('Cookie: PHPSESSID=' . $sessionId);
        return 'curl --silent --show-error --interface ' . escapeshellarg($source) . ' -D - -H ' . escapeshellarg('Content-Type: application/json') . $cookie . ' --data ' . escapeshellarg($payload) . ' -w ' . escapeshellarg("\nHTTP_CODE:%{http_code}\n") . ' ' . escapeshellarg('http://127.0.0.1:' . self::$port . $path);
    }

    private static function sessionIdFromHeaders(string $headers): string
    {
        preg_match('/^Set-Cookie:\s*PHPSESSID=([^;\r\n]+)/mi', $headers, $match);
        if (!isset($match[1])) {
            throw new RuntimeException('Expected PHPSESSID Set-Cookie header, received: ' . $headers);
        }
        return $match[1];
    }

    private static function createUser(string $username, string $password, int $active): void
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $email = 'u' . bin2hex(random_bytes(8)) . '@e.io';
        $stmt = self::$db->prepare('INSERT INTO users (username, password, email, first, last, isadmin, active, siteMemberships) VALUES (?, ?, ?, ?, ?, 0, ?, ?)');
        if ($stmt === false) {
            throw new RuntimeException(self::$db->error);
        }
        $first = 'Task'; $last = 'Two'; $memberships = '[]';
        $stmt->bind_param('sssssis', $username, $hash, $email, $first, $last, $active, $memberships);
        $stmt->execute();
    }

    private static function insertToken(string $username, string $token, string $purpose, string $expiry): void
    {
        self::$lastToken = $token;
        $hash = hash('sha256', $token, true);
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime($expiry));
        $stmt = self::$db->prepare('INSERT INTO activation_tokens (username, token_hash, purpose, expires_at) VALUES (?, ?, ?, ?)');
        if ($stmt === false) {
            throw new RuntimeException(self::$db->error);
        }
        $stmt->bind_param('ssss', $username, $hash, $purpose, $expiresAt);
        $stmt->execute();
    }

    private static function cleanDatabase(): void
    {
        $prefix = self::$runId . '%';
        $stmt = self::$db->prepare('DELETE FROM users WHERE username LIKE ?');
        if ($stmt !== false) {
            $stmt->bind_param('s', $prefix);
            $stmt->execute();
        }
        foreach (array_merge(
            array_map(fn(int $i): string => self::$runId . '_source_' . $i, range(0, 6)),
            [self::$runId . '_source_six', self::$runId . '_username'],
            array_map(fn(int $i): string => '127.0.0.' . $i, range(1, 7))
        ) as $key) {
            $hmac = HmacPrivacyKey::generateThrottleKey($key);
            foreach ([['login_attempts_by_source', 'source_hmac'], ['login_attempts_by_username', 'username_hmac']] as [$table, $column]) {
                $statement = self::$db->prepare("DELETE FROM $table WHERE $column = ?");
                if ($statement !== false) {
                    $statement->bind_param('s', $hmac);
                    $statement->execute();
                }
            }
        }
    }

    private static function clearSourceThrottle(string $source): void
    {
        $hmac = HmacPrivacyKey::generateThrottleKey($source);
        $stmt = self::$db->prepare('DELETE FROM login_attempts_by_source WHERE source_hmac = ?');
        if ($stmt === false) {
            throw new RuntimeException(self::$db->error);
        }
        $stmt->bind_param('s', $hmac);
        $stmt->execute();
    }

    private static function insertExpiredThrottleRecord(string $table, string $column, string $hmac): void
    {
        $statement = self::$db->prepare("INSERT INTO $table ($column, attempt_count, last_attempt_at, expires_at) VALUES (?, 5, UTC_TIMESTAMP(), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND))");
        if ($statement === false) {
            throw new RuntimeException(self::$db->error);
        }
        $statement->bind_param('s', $hmac);
        $statement->execute();
    }

    private static function throttleAttemptCount(string $table, string $column, string $hmac): int
    {
        $statement = self::$db->prepare("SELECT attempt_count FROM $table WHERE $column = ?");
        if ($statement === false) {
            throw new RuntimeException(self::$db->error);
        }
        $statement->bind_param('s', $hmac);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        if ($row === null) {
            throw new RuntimeException('Throttle record was not found.');
        }
        return (int) $row['attempt_count'];
    }

    private static function deleteThrottleRecord(string $table, string $column, string $hmac): void
    {
        $statement = self::$db->prepare("DELETE FROM $table WHERE $column = ?");
        if ($statement === false) {
            throw new RuntimeException(self::$db->error);
        }
        $statement->bind_param('s', $hmac);
        $statement->execute();
    }

    private static function database(): mysqli
    {
        $db = getDefaultDbConnection();
        if (!$db instanceof mysqli) {
            throw new RuntimeException('Task2 tests require the postportal-dev MySQL environment.');
        }
        return $db;
    }

    private static function startProcess(string $command): array
    {
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start concurrent curl request.');
        }
        return ['process' => $process, 'pipes' => $pipes];
    }

    private static function executeCommand(string $command): string
    {
        $output = [];
        $status = 0;
        exec($command . ' 2>&1', $output, $status);
        if ($status !== 0) {
            throw new RuntimeException('Command failed: ' . implode("\n", $output));
        }
        return implode("\n", $output);
    }

    private static function removeTree(string $path): void
    {
        if ($path !== '' && str_starts_with($path, sys_get_temp_dir() . '/t2_')) {
            self::executeCommand('rm -rf ' . escapeshellarg($path));
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Security\Runtime;

use DOMDocument;
use DOMXPath;
use mysqli;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Exercises the current source through an isolated PHP built-in-server runtime.
 */
final class CurrentSourceRouteRuntimeTest extends TestCase
{
    private string $runtimeDirectory;
    private string $sourceDirectory;
    private string $sessionDirectory;
    private string $routerPath;
    private string $baseUrl;
    private string $marker;
    private string $username;
    private string $adminSessionId;
    private string $nonAdminSessionId;
    private int $publishedPostId;
    private int $draftPostId;
    private ?mysqli $database = null;
    /** @var resource|null */
    private $serverProcess = null;
    /** @var array<int, resource> */
    private array $serverPipes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->marker = 'runtime-' . bin2hex(random_bytes(8));
        $this->username = 'rt' . bin2hex(random_bytes(8));
        $this->adminSessionId = bin2hex(random_bytes(16));
        $this->nonAdminSessionId = bin2hex(random_bytes(16));
        $this->runtimeDirectory = sys_get_temp_dir() . '/postportal-runtime-' . $this->marker;
        $this->sourceDirectory = $this->runtimeDirectory . '/src';
        $this->sessionDirectory = $this->runtimeDirectory . '/sessions';
        $this->routerPath = $this->runtimeDirectory . '/router.php';

        $this->createRuntime();
        $this->database = $this->connectDatabase();
        $this->createFixtures();
        $this->createSessions();
        $this->startServer();
    }

    protected function tearDown(): void
    {
        $this->stopServer();
        $this->removeFixtures();
        $this->removeDirectory($this->runtimeDirectory);
        parent::tearDown();
    }

    public function testRealHttpRouteAuthorizationAndRendering(): void
    {
        foreach (['home', 'post', 'login'] as $route) {
            $response = $this->get('/?page=' . $route);
            self::assertSame(200, $response['status'], "{$route} must render successfully; " . $this->serverDiagnostics());
        }

        $attackerSessionId = 'unknown' . bin2hex(random_bytes(20));
        $login = $this->get('/?page=login', $attackerSessionId);
        self::assertSame(200, $login['status']);
        $setCookie = $login['headers']['set-cookie'] ?? '';
        self::assertMatchesRegularExpression('/PHPSESSID=(?!' . preg_quote($attackerSessionId, '/') . '(?:;|$))[^;]+/', $setCookie);
        self::assertMatchesRegularExpression('/;\s*HttpOnly(?:;|$)/i', $setCookie);
        self::assertMatchesRegularExpression('/;\s*SameSite=Lax(?:;|$)/i', $setCookie);

        $anonymousAdmin = $this->get('/?page=admin');
        self::assertSame(302, $anonymousAdmin['status']);
        self::assertSame('/?page=login', $anonymousAdmin['headers']['location'] ?? null);

        $nonAdminPage = $this->get('/?page=admin', $this->nonAdminSessionId);
        self::assertSame(302, $nonAdminPage['status']);
        self::assertSame('/?page=login', $nonAdminPage['headers']['location'] ?? null);

        $adminPage = $this->get('/?page=admin', $this->adminSessionId);
        self::assertSame(200, $adminPage['status']);

        foreach ([
            '../admin' => rawurlencode('../admin'),
            './admin' => rawurlencode('./admin'),
            '%2e%2e%2fadmin' => '%2e%2e%2fadmin',
            '<img src=x onerror=alert(1)>' => rawurlencode('<img src=x onerror=alert(1)>'),
            '<svg onload=alert(1)>' => rawurlencode('<svg onload=alert(1)>'),
            'admin%00.txt' => rawurlencode('admin%00.txt'),
        ] as $route => $encodedRoute) {
            $response = $this->get('/?page=' . $encodedRoute);
            self::assertSame(404, $response['status'], "{$route} must be rejected");
            self::assertSame('Not found', trim($response['body']));
        }

        $this->assertDraftAccessAndLists();
        $this->assertEscapedPublishedPost();
        $this->assertAdminControls();
    }

    public function testCookieLessPostsApiRequestDoesNotCreateSession(): void
    {
        $sessionFilesBefore = glob($this->sessionDirectory . '/sess_*') ?: [];

        $response = $this->get('/api/posts.php?limit=50');

        self::assertSame(200, $response['status']);
        self::assertArrayNotHasKey('set-cookie', $response['headers']);
        self::assertSame($sessionFilesBefore, glob($this->sessionDirectory . '/sess_*') ?: []);
    }

    public function testMetricsUpdateDoesNotChangeContentTimestamp(): void
    {
        $before = $this->database->query('SELECT updated_at FROM posts WHERE id = ' . $this->publishedPostId)->fetch_assoc();
        $repository = new \PostPortal\Repository\PostRepository($this->database);

        self::assertNotNull($before);
        self::assertTrue($repository->incrementMetrics($this->publishedPostId, 1, 0, 1, 0));

        $after = $this->database->query('SELECT impression_count, view_count, updated_at FROM posts WHERE id = ' . $this->publishedPostId)->fetch_assoc();
        self::assertNotNull($after);
        self::assertSame(1, (int) $after['impression_count']);
        self::assertSame(1, (int) $after['view_count']);
        self::assertSame($before['updated_at'], $after['updated_at']);
    }

    private function assertDraftAccessAndLists(): void
    {
        foreach ([null, $this->nonAdminSessionId] as $sessionId) {
            $draft = $this->get('/api/posts.php?id=' . $this->draftPostId, $sessionId);
            self::assertSame(404, $draft['status'], 'Draft detail must not be disclosed to non-admin users: ' . $draft['body']);

            $list = $this->get('/api/posts.php?limit=50', $sessionId);
            self::assertSame(200, $list['status']);
            self::assertStringNotContainsString($this->marker . '-draft', $list['body']);

            $home = $this->get('/?page=home', $sessionId);
            self::assertSame(200, $home['status']);
            self::assertStringNotContainsString($this->marker . '-draft', $home['body']);
        }

        $draft = $this->get('/api/posts.php?id=' . $this->draftPostId, $this->adminSessionId);
        self::assertSame(200, $draft['status']);
        self::assertStringContainsString($this->marker . '-draft', $draft['body']);

        $list = $this->get('/api/posts.php?limit=50', $this->adminSessionId);
        self::assertSame(200, $list['status']);
        self::assertStringContainsString($this->marker . '-draft', $list['body']);

        $home = $this->get('/?page=home', $this->adminSessionId);
        self::assertSame(200, $home['status']);
        self::assertStringContainsString($this->marker . '-draft', $home['body']);
    }

    private function assertEscapedPublishedPost(): void
    {
        $response = $this->get('/?page=home');
        self::assertSame(200, $response['status']);

        $title = '<img src=x onerror=alert(1)> ' . $this->marker . '-published';
        $authorFirst = '<svg>' . $this->marker;
        self::assertStringContainsString(htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $response['body']);
        self::assertStringContainsString(htmlspecialchars($authorFirst, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $response['body']);

        $document = new DOMDocument();
        @$document->loadHTML($response['body']);
        $xpath = new DOMXPath($document);
        $post = $xpath->query('//*[@id="post-' . $this->publishedPostId . '"]')->item(0);
        self::assertNotNull($post, 'Published fixture must be rendered in the timeline');
        self::assertSame($title, trim((string) $xpath->evaluate('string(.//h5[contains(@class, "card-title")])', $post)));
        self::assertSame('By ' . $authorFirst . ' Runtime Author', trim((string) $xpath->evaluate('string(.//em)', $post)));
        self::assertSame(0, $xpath->query('.//img | .//svg', $post)->length, 'Fixture content must not create image or SVG nodes');
        self::assertSame(0, $xpath->query('.//*[@onerror or @onload or @onclick or @onmouseover]', $post)->length, 'Fixture content must not create event-handler nodes');
    }

    private function assertAdminControls(): void
    {
        foreach ([null, $this->nonAdminSessionId] as $sessionId) {
            $response = $this->get('/?page=home', $sessionId);
            self::assertStringNotContainsString('Admin Dashboard', $response['body']);
            self::assertStringNotContainsString('Create Post', $response['body']);
            self::assertStringNotContainsString('createPostModal', $response['body']);
            self::assertStringNotContainsString('postEditorModal', $response['body']);
            self::assertStringNotContainsString('btn-edit-section', $response['body']);
            self::assertStringNotContainsString('btn-edit-post-home', $response['body']);
            self::assertStringNotContainsString('btn-delete-post-home', $response['body']);
        }

        $response = $this->get('/?page=home', $this->adminSessionId);
        self::assertStringContainsString('Admin Dashboard', $response['body']);
        self::assertStringContainsString('Create Post', $response['body']);
        self::assertStringContainsString('createPostModal', $response['body']);
        self::assertStringContainsString('postEditorModal', $response['body']);
        self::assertStringContainsString('btn-edit-section', $response['body']);
        self::assertStringContainsString('btn-edit-post-home', $response['body']);
        self::assertStringContainsString('btn-delete-post-home', $response['body']);
    }

    private function createRuntime(): void
    {
        $repositoryRoot = dirname(__DIR__, 3);
        $originalSource = is_dir($repositoryRoot . '/src') ? $repositoryRoot . '/src' : $repositoryRoot;
        if (!mkdir($this->runtimeDirectory, 0700, true) || !mkdir($this->sessionDirectory, 0700, true)) {
            throw new RuntimeException('Unable to create isolated runtime directory');
        }

        $this->copyDirectory($originalSource, $this->sourceDirectory);
        mkdir($this->sourceDirectory . '/cache', 0700);
        mkdir($this->sourceDirectory . '/templates_c', 0700);
        $this->correctLibSplit();
        if (!symlink($repositoryRoot . '/vendor', $this->sourceDirectory . '/vendor')) {
            throw new RuntimeException('Unable to expose the root Composer vendor directory to the copied source');
        }

        file_put_contents($this->routerPath, <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$file = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
PHP
);
    }

    private function correctLibSplit(): void
    {
        $lowercase = $this->sourceDirectory . '/lib';
        $uppercase = $this->sourceDirectory . '/Lib';
        if (!is_dir($lowercase) && is_dir($uppercase) && !symlink('Lib', $lowercase)) {
            throw new RuntimeException('Unable to create the lowercase lib alias');
        }
        if (!is_dir($uppercase) && is_dir($lowercase) && !symlink('lib', $uppercase)) {
            throw new RuntimeException('Unable to create the uppercase Lib alias');
        }
    }

    private function connectDatabase(): mysqli
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        return new mysqli(
            getenv('MYSQL_HOST') ?: 'localhost',
            getenv('MYSQL_USER') ?: 'postportal',
            getenv('MYSQL_PASSWORD') ?: 'postportal',
            getenv('MYSQL_DATABASE') ?: 'postportal',
        );
    }

    private function createFixtures(): void
    {
        $user = $this->database->prepare('INSERT INTO users (username, password, first, last, isadmin, active, siteMemberships, email) VALUES (?, ?, ?, ?, 1, 1, ?, ?)');
        $password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $authorFirst = '<svg>' . $this->marker;
        $authorLast = 'Runtime Author';
        $memberships = '[]';
        $email = $this->username . '@example.test';
        $user->bind_param('ssssss', $this->username, $password, $authorFirst, $authorLast, $memberships, $email);
        $user->execute();

        $publishedTitle = '<img src=x onerror=alert(1)> ' . $this->marker . '-published';
        $draftTitle = $this->marker . '-draft';
        $body = '<p>Runtime fixture</p>';
        $status = 'published';
        $publishedAt = gmdate('Y-m-d H:i:s');
        $post = $this->database->prepare('INSERT INTO posts (title, body_html, status, published_at, created_by_user_id) VALUES (?, ?, ?, ?, ?)');
        $post->bind_param('sssss', $publishedTitle, $body, $status, $publishedAt, $this->username);
        $post->execute();
        $this->publishedPostId = $this->database->insert_id;

        $status = 'draft';
        $nullPublishedAt = null;
        $post->bind_param('sssss', $draftTitle, $body, $status, $nullPublishedAt, $this->username);
        $post->execute();
        $this->draftPostId = $this->database->insert_id;
    }

    private function createSessions(): void
    {
        $this->writeSession($this->adminSessionId, true);
        $this->writeSession($this->nonAdminSessionId, false);
    }

    private function writeSession(string $id, bool $isAdmin): void
    {
        $values = [
            'authenticated' => true,
            'username' => $this->username,
            'isadmin' => $isAdmin,
            'displayname' => 'Runtime User',
        ];
        $encoded = '';
        foreach ($values as $key => $value) {
            $encoded .= $key . '|';
            $encoded .= is_bool($value) ? 'b:' . ($value ? '1' : '0') . ';' : 's:' . strlen($value) . ':"' . $value . '";';
        }
        if (file_put_contents($this->sessionDirectory . '/sess_' . $id, $encoded, LOCK_EX) === false) {
            throw new RuntimeException('Unable to create strict-mode-valid session fixture');
        }
    }

    private function startServer(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($socket === false) {
            throw new RuntimeException('Unable to reserve a runtime port: ' . $errorMessage);
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr(strrchr((string) $name, ':'), 1);
        $this->baseUrl = 'http://127.0.0.1:' . $port;

        $command = implode(' ', [
            escapeshellarg(PHP_BINARY),
            '-d', escapeshellarg('session.save_path=' . $this->sessionDirectory),
            '-S', escapeshellarg('127.0.0.1:' . $port),
            '-t', escapeshellarg($this->sourceDirectory),
            escapeshellarg($this->routerPath),
        ]);
        $parentEnvironment = getenv();
        $environment = array_merge(is_array($parentEnvironment) ? $parentEnvironment : [], [
            'APP_URL' => $this->baseUrl,
            'DEBUG' => 'false',
            'SMARTY_DEBUG' => 'false',
        ]);
        $this->serverProcess = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $this->serverPipes, null, $environment);
        if (!is_resource($this->serverProcess)) {
            throw new RuntimeException('Unable to start isolated PHP runtime server');
        }
        fclose($this->serverPipes[0]);
        stream_set_blocking($this->serverPipes[2], false);

        for ($attempt = 0; $attempt < 50; $attempt++) {
            try {
                if ($this->get('/?page=home')['status'] > 0) {
                    return;
                }
            } catch (RuntimeException) {
                usleep(100000);
            }
        }
        throw new RuntimeException('Isolated PHP runtime server did not become reachable');
    }

    /** @return array{status:int, headers:array<string, string>, body:string} */
    private function get(string $path, ?string $sessionId = null): array
    {
        $headers = ['Connection: close'];
        if ($sessionId !== null) {
            $headers[] = 'Cookie: PHPSESSID=' . $sessionId;
        }
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => 5,
            'follow_location' => 0,
        ]]);
        $body = @file_get_contents($this->baseUrl . $path, false, $context);
        // Avoid PHP 8.5's compile-time deprecation for the predefined response-header variable.
        $responseHeaders = function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers()
            : (get_defined_vars()['http_response_header'] ?? null);
        if ($body === false || !is_array($responseHeaders) || !isset($responseHeaders[0])) {
            throw new RuntimeException('HTTP request failed for ' . $this->baseUrl . $path);
        }
        preg_match('/\s(\d{3})\s/', $responseHeaders[0], $matches);
        $parsedHeaders = [];
        foreach (array_slice($responseHeaders, 1) as $header) {
            if (str_contains($header, ':')) {
                [$name, $value] = explode(':', $header, 2);
                $parsedHeaders[strtolower(trim($name))] = trim($value);
            }
        }
        return ['status' => (int) ($matches[1] ?? 0), 'headers' => $parsedHeaders, 'body' => $body];
    }

    private function stopServer(): void
    {
        foreach ($this->serverPipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
        }
    }

    private function serverDiagnostics(): string
    {
        if (!isset($this->serverPipes[2]) || !is_resource($this->serverPipes[2])) {
            return 'server stderr unavailable';
        }
        return trim((string) stream_get_contents($this->serverPipes[2]));
    }

    private function removeFixtures(): void
    {
        if ($this->database === null) {
            return;
        }
        try {
            if (isset($this->publishedPostId, $this->draftPostId)) {
                $posts = $this->database->prepare('DELETE FROM posts WHERE id IN (?, ?) AND created_by_user_id = ?');
                $posts->bind_param('iis', $this->publishedPostId, $this->draftPostId, $this->username);
                $posts->execute();
            }
            if (isset($this->username)) {
                $user = $this->database->prepare('DELETE FROM users WHERE username = ?');
                $user->bind_param('s', $this->username);
                $user->execute();
            }
        } finally {
            $this->database->close();
            $this->database = null;
        }
    }

    private function copyDirectory(string $source, string $destination): void
    {
        mkdir($destination, 0700, true);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            $relativePath = $iterator->getSubPathName();
            $topLevelDirectory = strtok($relativePath, DIRECTORY_SEPARATOR);
            if (in_array($topLevelDirectory, ['vendor', 'cache', 'templates_c'], true)) {
                continue;
            }
            $target = $destination . '/' . $relativePath;
            if ($item->isDir()) {
                mkdir($target, 0700, true);
            } elseif (!copy($item->getPathname(), $target)) {
                throw new RuntimeException('Unable to copy source file ' . $item->getPathname());
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) && !is_link($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}

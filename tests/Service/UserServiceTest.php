<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PostPortal\Repository\UserRepository;
use PostPortal\Service\UserService;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/functions.php';
require_once __DIR__ . '/../../src/Repository/BaseRepository.php';
require_once __DIR__ . '/../../src/Repository/UserRepositoryInterface.php';
require_once __DIR__ . '/../../src/Repository/UserRepository.php';
require_once __DIR__ . '/../../src/Service/UserServiceInterface.php';
require_once __DIR__ . '/../../src/Service/UserService.php';

final class UserServiceTest extends TestCase
{
    private mysqli $db;
    private UserRepository $repository;
    private UserService $service;
    private string $username;

    protected function setUp(): void
    {
        $this->db = $this->database();
        $this->repository = new UserRepository($this->db);
        $this->service = new UserService($this->repository);
        $this->username = 'task2_service_' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->deleteUser($this->username);
        $this->db->close();
    }

    public function testEmailActivatedUserIsInactiveAndCannotUseSuppliedPassword(): void
    {
        $result = $this->service->createUser([
            'username' => $this->username,
            'email' => 'u' . bin2hex(random_bytes(4)) . '@e.io',
            'password' => 'AdminChosenPassword9',
            'send_email' => true,
            'first' => 'Task',
            'last' => 'Two',
        ]);

        self::assertTrue($result['success']);
        $stmt = $this->db->prepare('SELECT password, active FROM users WHERE username = ?');
        self::assertNotFalse($stmt);
        $stmt->bind_param('s', $this->username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        self::assertIsArray($row);
        self::assertSame('0', (string) $row['active']);
        self::assertFalse(password_verify('AdminChosenPassword9', (string) $row['password']));
    }

    public function testActivationTokenContractUsesRawTokenAndStoresOnlyHash(): void
    {
        self::assertTrue($this->repository->create([
            'username' => $this->username,
            'password' => 'unusable',
            'email' => 'u' . bin2hex(random_bytes(4)) . '@e.io',
            'first' => 'Task',
            'last' => 'Two',
            'active' => 0,
        ]));

        $result = $this->service->issueActivationToken($this->username);
        self::assertTrue($result['success']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/i', (string) $result['token']);
        self::assertIsString($result['expires_at']);
        self::assertGreaterThan(time(), strtotime($result['expires_at']));

        $stmt = $this->db->prepare('SELECT token_hash, purpose FROM activation_tokens WHERE username = ?');
        self::assertNotFalse($stmt);
        $stmt->bind_param('s', $this->username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        self::assertIsArray($row);
        self::assertSame('activate', $row['purpose']);
        self::assertSame(hash('sha256', (string) $result['token'], true), $row['token_hash']);
    }

    private function database(): mysqli
    {
        $db = getDefaultDbConnection();
        if (!$db instanceof mysqli) {
            throw new RuntimeException('Task2 tests require the postportal-dev MySQL environment.');
        }
        return $db;
    }

    private function deleteUser(string $username): void
    {
        $stmt = $this->db->prepare('DELETE FROM users WHERE username = ?');
        if ($stmt !== false) {
            $stmt->bind_param('s', $username);
            $stmt->execute();
        }
    }
}

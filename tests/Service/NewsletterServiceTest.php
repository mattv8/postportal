<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PostPortal\Repository\NewsletterRepository;
use PostPortal\Service\NewsletterService;
use PostPortal\Service\NewsletterVerificationMailerInterface;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/functions.php';
require_once __DIR__ . '/../../src/Repository/BaseRepository.php';
require_once __DIR__ . '/../../src/Repository/NewsletterRepositoryInterface.php';
require_once __DIR__ . '/../../src/Repository/NewsletterRepository.php';
require_once __DIR__ . '/../../src/Service/NewsletterVerificationMailerInterface.php';
require_once __DIR__ . '/../../src/Service/NewsletterServiceInterface.php';
require_once __DIR__ . '/../../src/Service/NewsletterService.php';

final class NewsletterServiceTest extends TestCase
{
    private mysqli $db;
    private NewsletterRepository $repository;
    private CapturingNewsletterMailer $mailer;
    private NewsletterService $service;
    /** @var array<int, string> */
    private array $emails = [];

    protected function setUp(): void
    {
        $db = getDefaultDbConnection();
        if (!$db instanceof mysqli) {
            throw new RuntimeException('Newsletter service tests require the configured MySQL environment.');
        }
        $this->db = $db;
        $this->repository = new NewsletterRepository($this->db);
        $this->mailer = new CapturingNewsletterMailer();
        $this->service = new NewsletterService($this->repository, $this->mailer);
    }

    protected function tearDown(): void
    {
        foreach ($this->emails as $email) {
            $statement = $this->db->prepare('DELETE FROM newsletter_subscribers WHERE email = ?');
            if ($statement !== false) {
                $statement->bind_param('s', $email);
                $statement->execute();
            }
        }
        $this->db->close();
    }

    public function testNewSubscriptionIsPendingHashOnlyAndMailedOnce(): void
    {
        $email = $this->email();
        $result = $this->service->subscribe($email, '127.0.0.1');

        self::assertSame(['success' => true, 'message' => 'Thank you for your interest'], $result);
        self::assertArrayNotHasKey('verification_token', $result);
        self::assertCount(1, $this->mailer->messages);
        self::assertSame($email, $this->mailer->messages[0]['email']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $this->mailer->messages[0]['token']);

        $row = $this->repository->findByEmail($email);
        self::assertNotNull($row);
        self::assertSame('0', (string) $row['is_active']);
        self::assertSame(hash('sha256', $this->mailer->messages[0]['token']), $row['verification_token']);
        self::assertNotSame($this->mailer->messages[0]['token'], $row['verification_token']);
        self::assertNotNull($row['verification_token_expires_at']);
        self::assertGreaterThan(time() + 86000, strtotime((string) $row['verification_token_expires_at'] . ' UTC'));
    }

    public function testExistingInactiveAndActiveSubscriptionsAreIndistinguishableAndNotRemailed(): void
    {
        $email = $this->email();
        $new = $this->service->subscribe($email);
        $inactive = $this->service->subscribe($email);
        self::assertSame($new, $inactive);
        self::assertCount(1, $this->mailer->messages);

        self::assertTrue($this->service->verifySubscriber($this->mailer->messages[0]['token'])['success']);
        $active = $this->service->subscribe($email);
        self::assertSame($inactive, $active);
        self::assertCount(1, $this->mailer->messages);
    }

    public function testDeliveredTokenActivatesOnceAndExpiredTokenFails(): void
    {
        $email = $this->email();
        $this->service->subscribe($email);
        $token = $this->mailer->messages[0]['token'];
        self::assertTrue($this->service->verifySubscriber($token)['success']);
        self::assertFalse($this->service->verifySubscriber($token)['success']);

        $expiredEmail = $this->email();
        $this->service->subscribe($expiredEmail);
        $statement = $this->db->prepare('UPDATE newsletter_subscribers SET verification_token_expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE email = ?');
        self::assertNotFalse($statement);
        $statement->bind_param('s', $expiredEmail);
        $statement->execute();
        self::assertFalse($this->service->verifySubscriber($this->mailer->messages[1]['token'])['success']);
    }

    public function testPublicMailFailureOrExceptionRollsBackAndStaysGeneric(): void
    {
        $generic = ['success' => true, 'message' => 'Thank you for your interest'];
        $this->mailer->result = false;
        $failedEmail = $this->email();
        self::assertSame($generic, $this->service->subscribe($failedEmail));
        self::assertNull($this->repository->findByEmail($failedEmail));

        $this->mailer->result = new RuntimeException('SMTP diagnostics must not escape');
        $exceptionEmail = $this->email();
        self::assertSame($generic, $this->service->subscribe($exceptionEmail));
        self::assertNull($this->repository->findByEmail($exceptionEmail));
    }

    public function testAdminAddIsPendingAndDoesNotReturnToken(): void
    {
        $email = $this->email();
        $result = $this->service->addSubscriber($email);
        self::assertTrue($result['success']);
        self::assertArrayNotHasKey('verification_token', $result);
        self::assertCount(1, $this->mailer->messages);
        self::assertSame('0', (string) $this->repository->findByEmail($email)['is_active']);
    }

    public function testArchiveThenReactivateSendsFreshPendingTokenAndCanVerify(): void
    {
        $email = $this->email();
        $this->service->subscribe($email);
        self::assertTrue($this->service->verifySubscriber($this->mailer->messages[0]['token'])['success']);
        $subscriber = $this->repository->findByEmail($email);
        self::assertNotNull($subscriber);
        self::assertTrue($this->service->archiveSubscriber((int) $subscriber['id'])['success']);

        $result = $this->service->reactivateSubscriber((int) $subscriber['id']);
        self::assertTrue($result['success']);
        self::assertArrayNotHasKey('verification_token', $result);
        self::assertCount(2, $this->mailer->messages);
        self::assertNotSame($this->mailer->messages[0]['token'], $this->mailer->messages[1]['token']);
        self::assertSame('0', (string) $this->repository->findByEmail($email)['is_active']);
        self::assertTrue($this->service->verifySubscriber($this->mailer->messages[1]['token'])['success']);
    }

    public function testUpdateSubscriberStatusTrueDoesNotDirectlyActivate(): void
    {
        $email = $this->email();
        $this->service->addSubscriber($email);
        $subscriber = $this->repository->findByEmail($email);
        self::assertNotNull($subscriber);

        self::assertTrue($this->service->updateSubscriberStatus((int) $subscriber['id'], true)['success']);
        self::assertSame('0', (string) $this->repository->findByEmail($email)['is_active']);
        self::assertCount(2, $this->mailer->messages);
    }

    private function email(): string
    {
        $email = 'task4_' . bin2hex(random_bytes(8)) . '@example.test';
        $this->emails[] = $email;
        return $email;
    }
}

final class CapturingNewsletterMailer implements NewsletterVerificationMailerInterface
{
    /** @var array<int, array{email:string, token:string}> */
    public array $messages = [];
    public bool|Throwable $result = true;

    public function sendVerificationEmail(string $email, string $rawToken): bool
    {
        $this->messages[] = ['email' => $email, 'token' => $rawToken];
        if ($this->result instanceof Throwable) {
            throw $this->result;
        }
        return $this->result;
    }
}

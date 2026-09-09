<?php

declare(strict_types=1);

namespace PostPortal\Service;

use PostPortal\Repository\NewsletterRepositoryInterface;

final class NewsletterService implements NewsletterServiceInterface
{
    private const TOKEN_EXPIRY_SECONDS = 86400;
    private const PUBLIC_SUCCESS = ['success' => true, 'message' => 'Thank you for your interest'];

    public function __construct(
        private NewsletterRepositoryInterface $newsletterRepository,
        private NewsletterVerificationMailerInterface $verificationMailer
    ) {
    }

    public function subscribe(string $email, ?string $ipAddress = null): array
    {
        $email = $this->normalizeEmail($email);
        if ($email === null) {
            return ['success' => false, 'error' => 'Unable to process subscription at this time'];
        }

        try {
            if ($this->newsletterRepository->findByEmail($email) !== null) {
                return self::PUBLIC_SUCCESS;
            }

            $this->createAndMailPendingSubscriber($email, $ipAddress);
        } catch (\Throwable) {
            // Do not log request identifiers, tokens, or transport diagnostics.
            error_log('Newsletter subscription processing failed');
        }

        // A valid public request has one indistinguishable response regardless of state or delivery.
        return self::PUBLIC_SUCCESS;
    }

    public function unsubscribe(string $email): array
    {
        try {
            return $this->newsletterRepository->unsubscribe($email)
                ? ['success' => true, 'message' => 'Successfully unsubscribed']
                : ['success' => false, 'error' => 'Email not found'];
        } catch (\Throwable) {
            error_log('Newsletter unsubscribe processing failed');
            return ['success' => false, 'error' => 'Failed to unsubscribe'];
        }
    }

    public function verifySubscriber(string $rawToken): array
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $rawToken)) {
            return ['success' => false, 'error' => 'Invalid or expired verification link'];
        }

        try {
            return $this->newsletterRepository->verify(hash('sha256', $rawToken), self::TOKEN_EXPIRY_SECONDS)
                ? ['success' => true, 'message' => 'Thank you! You have been added to our mailing list']
                : ['success' => false, 'error' => 'Invalid or expired verification link'];
        } catch (\Throwable) {
            error_log('Newsletter verification processing failed');
            return ['success' => false, 'error' => 'Unable to process verification at this time'];
        }
    }

    public function getActiveSubscriberCount(): int
    {
        try {
            return $this->newsletterRepository->getActiveSubscriberCount();
        } catch (\Throwable) {
            error_log('Newsletter subscriber count lookup failed');
            return 0;
        }
    }

    public function getTotalSubscriberCount(): int
    {
        try {
            return $this->newsletterRepository->getTotalSubscriberCount();
        } catch (\Throwable) {
            error_log('Newsletter subscriber count lookup failed');
            return 0;
        }
    }

    public function getActiveSubscriberEmails(): array
    {
        try {
            return $this->newsletterRepository->getActiveSubscriberEmails();
        } catch (\Throwable) {
            error_log('Newsletter subscriber list lookup failed');
            return [];
        }
    }

    public function getSubscribers(bool $activeOnly = true): array
    {
        try {
            return $this->newsletterRepository->getSubscribers($activeOnly);
        } catch (\Throwable) {
            error_log('Newsletter subscriber list lookup failed');
            return [];
        }
    }

    public function updateSubscriberStatus(int $id, bool $isActive): array
    {
        return $isActive ? $this->reactivateSubscriber($id) : $this->archiveSubscriber($id);
    }

    public function archiveSubscriber(int $id): array
    {
        try {
            return $this->newsletterRepository->archive($id)
                ? ['success' => true, 'message' => 'Subscriber archived successfully']
                : ['success' => false, 'error' => 'Subscriber not found'];
        } catch (\Throwable) {
            error_log('Newsletter archive processing failed');
            return ['success' => false, 'error' => 'Failed to archive subscriber'];
        }
    }

    public function reactivateSubscriber(int $id): array
    {
        try {
            $subscriber = $this->newsletterRepository->findById($id);
            if ($subscriber === null) {
                return ['success' => false, 'error' => 'Subscriber not found'];
            }

            $this->newsletterRepository->beginNewsletterTransaction();
            try {
                $rawToken = $this->newRawToken();
                if (!$this->newsletterRepository->setPendingVerification($id, hash('sha256', $rawToken))) {
                    throw new \RuntimeException('Subscriber update failed');
                }
                if (!$this->verificationMailer->sendVerificationEmail((string) $subscriber['email'], $rawToken)) {
                    throw new \RuntimeException('Verification mail delivery failed');
                }
                $this->newsletterRepository->commitNewsletterTransaction();
            } catch (\Throwable $exception) {
                $this->newsletterRepository->rollbackNewsletterTransaction();
                throw $exception;
            }

            return ['success' => true, 'message' => 'Subscriber reactivation queued - verification email sent'];
        } catch (\Throwable) {
            error_log('Newsletter reactivation processing failed');
            return ['success' => false, 'error' => 'Failed to reactivate subscriber'];
        }
    }

    public function addSubscriber(string $email): array
    {
        $email = $this->normalizeEmail($email);
        if ($email === null) {
            return ['success' => false, 'error' => 'Invalid email format'];
        }

        try {
            if ($this->newsletterRepository->findByEmail($email) !== null) {
                return ['success' => false, 'error' => 'Email already exists'];
            }
            $id = $this->createAndMailPendingSubscriber($email, null);
            return ['success' => true, 'message' => 'Verification email sent', 'id' => $id];
        } catch (\Throwable) {
            error_log('Newsletter admin add processing failed');
            return ['success' => false, 'error' => 'Failed to add subscriber'];
        }
    }

    private function createAndMailPendingSubscriber(string $email, ?string $ipAddress): int
    {
        $this->newsletterRepository->beginNewsletterTransaction();
        try {
            $rawToken = $this->newRawToken();
            $id = $this->newsletterRepository->create($email, hash('sha256', $rawToken), $ipAddress);
            if (!$this->verificationMailer->sendVerificationEmail($email, $rawToken)) {
                throw new \RuntimeException('Verification mail delivery failed');
            }
            $this->newsletterRepository->commitNewsletterTransaction();
            return $id;
        } catch (\Throwable $exception) {
            $this->newsletterRepository->rollbackNewsletterTransaction();
            throw $exception;
        }
    }

    private function normalizeEmail(string $email): ?string
    {
        $normalized = strtolower(trim($email));
        return strlen($normalized) <= 255 && filter_var($normalized, FILTER_VALIDATE_EMAIL) !== false
            ? $normalized
            : null;
    }

    private function newRawToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}

<?php

declare(strict_types=1);

namespace PostPortal\Repository;

interface NewsletterRepositoryInterface
{
    public function getActiveSubscriberCount(): int;
    public function getTotalSubscriberCount(): int;
    /** @return array<int, string> */
    public function getActiveSubscriberEmails(): array;
    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array;
    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array;
    public function create(string $email, string $verificationTokenHash, ?string $ipAddress = null): int;
    public function verify(string $tokenHash, int $maxAgeSeconds = 86400): bool;
    public function unsubscribe(string $email): bool;
    /** @return array<int, array<string, mixed>> */
    public function getSubscribers(bool $activeOnly = true): array;
    public function archive(int $id): bool;
    public function setPendingVerification(int $id, string $verificationTokenHash): bool;
    public function beginNewsletterTransaction(): void;
    public function commitNewsletterTransaction(): void;
    public function rollbackNewsletterTransaction(): void;
}

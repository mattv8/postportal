<?php

declare(strict_types=1);

namespace PostPortal\Service;

/**
 * Interface for newsletter service operations
 */
interface NewsletterServiceInterface
{
    /**
     * Subscribe an email to the newsletter
     *
     * @param string $email Email address
     * @param string|null $ipAddress Optional IP address for logging
     * @return array{success: bool, message?: string, error?: string}
     */
    public function subscribe(string $email, ?string $ipAddress = null): array;

    /**
     * Unsubscribe an email from the newsletter
     *
     * @param string $email Email address
     * @return array{success: bool, message?: string, error?: string}
     */
    public function unsubscribe(string $email): array;

    /**
     * Verify a subscriber using verification token
     *
     * @param string $token Verification token
     * @return array{success: bool, message?: string, error?: string}
     */
    public function verifySubscriber(string $token): array;

    /**
     * Get active subscriber count
     *
     * @return int
     */
    public function getActiveSubscriberCount(): int;

    /**
     * Get total subscriber count
     *
     * @return int
     */
    public function getTotalSubscriberCount(): int;

    /**
     * Get all subscribers with optional active filter
     *
     * @param bool $activeOnly Only include active subscribers
     * @return array<int, array<string, mixed>>
     */
    public function getSubscribers(bool $activeOnly = true): array;

    /**
     * Add a subscriber manually (admin action)
     *
     * @param string $email Email address
     * @return array{success: bool, id?: int, message?: string, error?: string}
     */
    public function addSubscriber(string $email): array;

    /**
     * Archive a subscriber (admin action)
     *
     * @param int $id Subscriber ID
     * @return array{success: bool, message?: string, error?: string}
     */
    public function archiveSubscriber(int $id): array;

    /**
     * Reactivate a subscriber (admin action)
     *
     * @param int $id Subscriber ID
     * @return array{success: bool, message?: string, error?: string}
     */
    public function reactivateSubscriber(int $id): array;

    /**
     * Update subscriber active status
     *
     * @param int $id Subscriber ID
     * @param bool $isActive New status
     * @return array{success: bool, message?: string, error?: string}
     */
    public function updateSubscriberStatus(int $id, bool $isActive): array;
}

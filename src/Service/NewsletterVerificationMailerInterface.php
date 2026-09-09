<?php

declare(strict_types=1);

namespace PostPortal\Service;

/**
 * Interface for newsletter verification email mailer
 *
 * Sends verification emails to newsletter subscribers with token-based activation links.
 */
interface NewsletterVerificationMailerInterface
{
    /**
     * Send newsletter verification email to subscriber
     *
     * @param string $email Subscriber email address (validated before calling)
     * @param string $rawToken Raw 64-hex verification token (never logged/stored plaintext)
     * @return bool True on successful send, false on generic failure
     *
     * @throws \RuntimeException Only for unrecoverable configuration errors (no SMTP config, no templates, etc.)
     *         For transient SMTP failures, returns false instead (generic to caller)
     */
    public function sendVerificationEmail(string $email, string $rawToken): bool;
}

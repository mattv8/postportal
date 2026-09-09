<?php

declare(strict_types=1);

/**
 * Newsletter Verification API
 * Handles email verification from double opt-in links
 * SEC-12: Verification activates inactive subscribers using hashed, expiring tokens
 *
 * Route: /api/newsletter-verify.php?token=<raw_token>
 */
use PostPortal\Http\ApiHandler;
use PostPortal\Http\ErrorResponse;

require_once __DIR__ . '/bootstrap.php';

ApiHandler::handle(function (): void {
    ['container' => $container] = bootstrapApi();
    $newsletterService = $container->getNewsletterService();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        ErrorResponse::json(405, 'Method not allowed');
    }

    // Get token from query parameter
    $token = $_GET['token'] ?? '';

    if (empty($token)) {
        ErrorResponse::badRequest('Verification token is required');
    }

    // Sanitize token (alphanumeric hex only, 64 chars)
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        ErrorResponse::badRequest('Invalid verification token format');
    }

    // Verify the subscriber
    $result = $newsletterService->verifySubscriber($token);

    if (!$result['success']) {
        // Generic error; do not expose token details or subscriber state
        ErrorResponse::json(400, 'Unable to verify subscription');
    }

    // Return generic success
    ErrorResponse::success([
        'message' => $result['message'] ?? 'Thank you! Your email has been verified.'
    ]);
});

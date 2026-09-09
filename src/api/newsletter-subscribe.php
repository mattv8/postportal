<?php

declare(strict_types=1);

/**
 * Newsletter Subscription API
 * Handles email subscription submissions from the mailing list form
 * Implements double opt-in with DB-backed global rate limiting
 * SEC-12: Returns indistinguishable responses; no raw tokens in logs/JSON
 */
use PostPortal\Http\ApiHandler;
use PostPortal\Http\ErrorResponse;

require_once __DIR__ . '/bootstrap.php';

ApiHandler::handle(function (): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ErrorResponse::json(405, 'Method not allowed');
    }

    $maxBodySize = 8192;
    $contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($contentLength !== false && $contentLength !== null && $contentLength > $maxBodySize) {
        ErrorResponse::json(413, 'Request body too large');
    }

    // Do not trust Content-Length: chunked and misreported requests are bounded here too.
    $rawBody = file_get_contents('php://input', false, null, 0, $maxBodySize + 1);
    if ($rawBody === false) {
        ErrorResponse::badRequest('Unable to process subscription');
    }
    if (strlen($rawBody) > $maxBodySize) {
        ErrorResponse::json(413, 'Request body too large');
    }
    $payload = json_decode($rawBody, true);
    if (!is_array($payload) || !array_key_exists('email', $payload)) {
        ErrorResponse::badRequest('Unable to process subscription');
    }

    ['container' => $container] = bootstrapApi();
    $newsletterService = $container->getNewsletterService();
    $throttleRepository = $container->getNewsletterThrottleRepository();

    $email = (string) $payload['email'];

    // Derive source key from client IP (not from potentially spoofed forwarded headers)
    // Use only REMOTE_ADDR for trust; do not use X-Forwarded-For, X-Real-IP, etc.
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    try {
        $sourceKey = \PostPortal\Repository\NewsletterThrottleRepository::deriveSourceKey($clientIp);
    } catch (\Throwable) {
        ErrorResponse::json(429, 'Too many requests');
    }

    // Check DB-backed global throttle (max 5 attempts per hour per source)
    if ($throttleRepository->isThrottled($sourceKey, 5, 3600)) {
        // Rate limit exceeded; return generic 429 without revealing details
        ErrorResponse::json(429, 'Too many requests');
    }

    // Perform subscription
    $result = $newsletterService->subscribe($email, $clientIp);

    if (!$result['success']) {
        // Generic error; do not expose validation details
        ErrorResponse::json(400, 'Unable to process subscription');
    }

    ErrorResponse::success($result);
});

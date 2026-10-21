<?php

declare(strict_types=1);

use PostPortal\Http\ApiHandler;
use PostPortal\Http\ErrorResponse;

require_once __DIR__ . '/bootstrap.php';

ApiHandler::handle(function (): void {
    ['container' => $container] = bootstrapApi(allowOptionalSession: false);
    $postService = $container->getPostService();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ErrorResponse::json(405, 'Method not allowed');
    }

    $payload = readJsonInput();
    $postId = isset($payload['post_id']) ? (int) $payload['post_id'] : 0;

    $impressionInc = !empty($payload['impression']) ? 1 : 0;
    $uniqueImpressionInc = !empty($payload['unique_impression']) ? 1 : 0;
    $viewInc = !empty($payload['view']) ? 1 : 0;
    $uniqueViewInc = !empty($payload['unique_view']) ? 1 : 0;

    if ($postId <= 0 || ($impressionInc + $uniqueImpressionInc + $viewInc + $uniqueViewInc) === 0) {
        ErrorResponse::badRequest('Invalid payload');
    }

    $result = $postService->incrementMetrics($postId, $impressionInc, $uniqueImpressionInc, $viewInc, $uniqueViewInc);
    if (!$result['success']) {
        $message = $result['error'] ?? 'Failed to update metrics';
        if ($message === 'Post not found or not published') {
            ErrorResponse::notFound($message);
        }
        ErrorResponse::badRequest($message);
    }

    ErrorResponse::success(['success' => true]);
});

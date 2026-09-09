<?php

declare(strict_types=1);

/**
 * Generate Title API Endpoint
 * Uses OpenAI to generate a post title from the content body
 */
use PostPortal\Http\ApiHandler;
use PostPortal\Http\ErrorResponse;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../vendor/autoload.php';

ApiHandler::handle(function (): void {
    ['container' => $container] = bootstrapApi(requireAuth: true, requireAdmin: false);

    $input = readJsonInput();
    if (!isset($input['content']) || empty(trim((string) $input['content']))) {
        ErrorResponse::badRequest('Content is required');
    }

    $content = trim((string) $input['content']);

    // Prefer env lookup to avoid scope issues when config is required inside helper functions
    $apiKey = getenv('OPENAI_API_KEY') ?: '';
    if (empty($apiKey)) {
        // Treat as a client-visible configuration issue, not a 500
        ErrorResponse::badRequest('OpenAI API key not configured');
    }

    $settingsRepository = $container->getSettingsRepository();
    $settings = $settingsRepository->getSettings();
    $systemPrompt = $settings['ai_system_prompt'] ?? DEFAULT_AI_SYSTEM_PROMPT;

    try {
        $client = OpenAI::client($apiKey);

        $plainContent = strip_tags($content);
        $maxContentLength = 2000;
        if (strlen($plainContent) > $maxContentLength) {
            $plainContent = substr($plainContent, 0, $maxContentLength) . '...';
        }

        $response = $client->chat()->create([
            'model' => 'gpt-4o-mini',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ],
                [
                    'role' => 'user',
                    'content' => "Create a title for this post:\n\n" . $plainContent,
                ],
            ],
            'max_tokens' => 100,
            'temperature' => 0.7,
        ]);

        $generatedTitle = trim($response->choices[0]->message->content);
        $generatedTitle = trim($generatedTitle, '"\'');

        ErrorResponse::success(['title' => $generatedTitle]);
    } catch (\Exception $e) {
        error_log('OpenAI API Error: ' . $e->getMessage());
        ErrorResponse::internalError('Failed to generate title');
    }
});

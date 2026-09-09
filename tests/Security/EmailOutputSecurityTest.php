<?php

declare(strict_types=1);

namespace PostPortal\Tests\Security;

use DOMDocument;
use DOMXPath;
use PostPortal\Http\ViewRenderer;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/functions.php';

final class EmailOutputSecurityTest extends TestCase
{
    public function testSmtpDiagnosticsRedactSecretsWhileRetainingProtocolContext(): void
    {
        $email = 'email-output-safety-8a4d@example.test';
        $authPayload = base64_encode('auth-user-8a4d:auth-password-8a4d');
        $diagnostic = "SMTP 535 Authentication failed\n"
            . "AUTH LOGIN {$authPayload}\n"
            . "password=password-8a4d bearer bearer-token-8a4d\n"
            . "Authorization: Basic authorization-value-8a4d\n"
            . "RCPT TO:<{$email}>\n"
            . "unsubscribe_token=unsubscribe-token-8a4d activation_token=activation-token-8a4d token=token-8a4d";

        $redacted = \redactSmtpDiagnostics($diagnostic);

        foreach ([$email, $authPayload, 'password-8a4d', 'bearer-token-8a4d', 'authorization-value-8a4d', 'unsubscribe-token-8a4d', 'activation-token-8a4d', 'token-8a4d'] as $secret) {
            self::assertStringNotContainsString($secret, $redacted);
        }
        self::assertStringContainsString('SMTP 535 Authentication failed', $redacted);
        self::assertStringContainsString('AUTH', $redacted);
        self::assertStringContainsString('RCPT TO:<', $redacted);
    }

    public function testNotificationTemplatesEscapeHostileFieldsAndPreserveSanitizedBodyMarkup(): void
    {
        $renderer = ViewRenderer::fromSmarty(null, false);
        $renderer->assign([
            'site_title' => 'Site <img src=x onerror=siteHandler()>',
            'author_name' => 'Author <svg onload=authorHandler()>',
            'excerpt' => 'Excerpt <iframe onload=excerptHandler()>',
            'post_title' => 'Post <script>postHandler()</script>',
            'post_url' => 'https://example.test/post" onmouseover="postUrlHandler()',
            'base_url' => 'https://example.test" onmouseover="baseUrlHandler()',
            'unsubscribe_url' => 'https://example.test/unsubscribe?token=x" onmouseover="unsubscribeUrlHandler()',
            'hero_image_url' => 'https://example.test/image" onerror="heroUrlHandler()',
            'hero_image_height' => 100,
            'hero_title_overlay' => 0,
            'hero_overlay_opacity' => 0.7,
            'body_html' => '<p><strong data-safe="yes">Sanitized body markup</strong></p>',
        ]);

        foreach (['email_notification_full.tpl', 'email_notification_excerpt.tpl'] as $template) {
            $document = new DOMDocument();
            self::assertTrue(@$document->loadHTML($renderer->getSmarty()->fetch($template)));
            $xpath = new DOMXPath($document);

            self::assertSame(0, $xpath->query('//script|//svg|//iframe|//img[@onerror]')->length, $template . ' must not create attacker nodes');
            self::assertSame(0, $xpath->query('//@onmouseover|//@onload|//@onerror')->length, $template . ' must not create attacker handlers');
            self::assertStringContainsString('Site <img src=x onerror=siteHandler()>', $document->textContent);
        }

        $fullDocument = new DOMDocument();
        self::assertTrue(@$fullDocument->loadHTML($renderer->getSmarty()->fetch('email_notification_full.tpl')));
        self::assertSame(1, (new DOMXPath($fullDocument))->query('//div[contains(@class, "post-body")]//strong')->length, 'Sanitized body HTML is intentionally rendered as markup');
    }
}

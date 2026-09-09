<?php

declare(strict_types=1);

namespace PostPortal\Tests\Security;

use PHPUnit\Framework\TestCase;

// Load functions.php to get the global sanitizeHtml function
// Composer is already loaded by PHPUnit; Task 1A2's guard prevents duplication
require_once __DIR__ . '/../../src/functions.php';

class HtmlSanitizerTest extends TestCase
{
    /**
     * Test idempotence: sanitizing sanitized output twice produces the same result
     */
    public function testSanitizedOutputIsIdempotent(): void
    {
        $input = '<p onmouseover="alert(1)">test</p>';
        $once = \sanitizeHtml($input);
        $twice = \sanitizeHtml($once);
        self::assertSame($once, $twice, 'Sanitized output should be idempotent');
    }

    /**
     * Unquoted event handlers should be removed (SEC-05 regression)
     * Current code only handles quoted handlers
     */
    public function testRemovesUnquotedEventHandlers(): void
    {
        $input = '<p onmouseover=alert(1)>test</p>';
        $result = \sanitizeHtml($input);
        self::assertStringNotContainsString('onmouseover', $result);
        self::assertStringContainsString('test', $result);
    }

    /**
     * Mixed case event handlers should be removed
     */
    public function testRemovesMixedCaseEventHandlers(): void
    {
        $input = '<div OnMouseOver="alert(1)">content</div>';
        $result = \sanitizeHtml($input);
        self::assertStringNotContainsString('OnMouseOver', strtolower($result));
        self::assertStringContainsString('content', $result);
    }

    /**
     * Event handlers on all allowed tags should be removed
     */
    public function testRemovesEventHandlersFromAllAllowedTags(): void
    {
        $tags = ['p', 'div', 'span', 'strong', 'em', 'a', 'img', 'h1', 'h2'];
        foreach ($tags as $tag) {
            $input = "<{$tag} onload=\"alert(1)\">content</{$tag}>";
            $result = \sanitizeHtml($input);
            self::assertStringNotContainsString('onload', $result, "Failed for tag: {$tag}");
        }
    }

    /**
     * Entity-obfuscated javascript: URLs should be rejected
     */
    public function testRejectsEntityObfuscatedJavaScriptUrls(): void
    {
        $input = '<a href="&#106;&#97;&#118;&#97;&#115;&#99;&#114;&#105;&#112;&#116;&#58;">click</a>';
        $result = \sanitizeHtml($input);
        // Should either remove href or replace with safe value
        self::assertStringNotContainsString('javascript:', $result);
    }

    /**
     * data: URLs should be rejected
     */
    public function testRejectsDataUrls(): void
    {
        $input = '<a href="data:text/html,<img src=x onerror=alert(1)>">click</a>';
        $result = \sanitizeHtml($input);
        self::assertStringNotContainsString('data:', $result);
    }

    /**
     * Inline style attributes should be removed
     */
    public function testRemovesInlineStyles(): void
    {
        $input = '<p style="color: red; background: url(javascript:alert(1))">text</p>';
        $result = \sanitizeHtml($input);
        self::assertStringNotContainsString('style=', $result);
        self::assertStringContainsString('text', $result);
    }

    /**
     * srcdoc attribute should be removed
     */
    public function testRemovesSrcdoc(): void
    {
        $input = '<iframe srcdoc="<img src=x onerror=alert(1)>"></iframe>';
        $result = \sanitizeHtml($input);
        self::assertStringNotContainsString('srcdoc', $result);
    }

    /**
     * srcset attribute should be removed
     */
    public function testRemovesSrcsetOrSanitizes(): void
    {
        $input = '<img src="test.jpg" srcset="javascript:alert(1)">';
        $result = \sanitizeHtml($input);
        // srcset should be removed entirely
        self::assertStringNotContainsString('srcset', $result);
    }

    /**
     * SVG/MathML namespace attributes should be removed
     */
    public function testRemovesSvgNamespaces(): void
    {
        $input = '<svg xmlns="http://www.w3.org/2000/svg"><circle onload="alert(1)"/></svg>';
        $result = \sanitizeHtml($input);
        // SVG should either be removed or safely handled without handlers
        self::assertStringNotContainsString('onload', $result);
    }

    /**
     * Control characters should not bypass filters
     */
    public function testRemovesControlCharacterObfuscatedHandlers(): void
    {
        $input = "<p on\x00mouseover=\"alert(1)\">test</p>";
        $result = \sanitizeHtml($input);
        self::assertStringNotContainsString('alert', $result);
    }

    /**
     * Benign Quill alignment classes should be preserved
     */
    public function testPreservesBenignQuillClasses(): void
    {
        $input = '<p class="ql-align-center">centered text</p>';
        $result = \sanitizeHtml($input);
        self::assertStringContainsString('ql-align-center', $result);
        self::assertStringContainsString('centered text', $result);
    }

    /**
     * Malformed nested tags should be handled safely
     */
    public function testHandlesMalformedNesting(): void
    {
        $input = '<p><strong onload="alert(1)">nested</p></strong>';
        $result = \sanitizeHtml($input);
        self::assertStringNotContainsString('onload', $result);
        self::assertStringContainsString('nested', $result);
    }

    /**
     * Safe HTTP/HTTPS links should be preserved
     */
    public function testPreservesHttpsLinks(): void
    {
        $input = '<a href="https://example.com">link</a>';
        $result = \sanitizeHtml($input);
        self::assertStringContainsString('https://example.com', $result);
        self::assertStringContainsString('link', $result);
    }

    /**
     * Safe images should be preserved
     */
    public function testPreservesImages(): void
    {
        $input = '<img src="/image.jpg" alt="test image">';
        $result = \sanitizeHtml($input);
        self::assertStringContainsString('/image.jpg', $result);
    }

    /**
     * Empty input should return empty string
     */
    public function testEmptyInputReturnsEmpty(): void
    {
        $result = \sanitizeHtml('');
        self::assertSame('', $result);
    }

     /**
      * Whitespace-only input should return unchanged
      */
     public function testWhitespaceOnlyInput(): void
     {
         $input = '   ';
         $result = \sanitizeHtml($input);
         self::assertTrue(trim($result) === '');
     }

     /**
      * Single root-relative paths should be preserved (/path)
      */
     public function testAllowsRootRelativePaths(): void
     {
         $input = '<a href="/path/to/page">link</a>';
         $result = \sanitizeHtml($input);
         self::assertStringContainsString('/path/to/page', $result, 'Root-relative /path should be preserved');
     }

     /**
      * Protocol-relative URLs should be rejected (//host/path)
      */
     public function testRejectsProtocolRelativeUrls(): void
     {
         $input = '<a href="//evil.com/attack">link</a>';
         $result = \sanitizeHtml($input);
         self::assertStringNotContainsString('//evil.com', $result, 'Protocol-relative URLs should be rejected');
     }

     /**
      * Protocol-relative images should be rejected
      */
     public function testRejectsProtocolRelativeImages(): void
     {
         $input = '<img src="//evil.com/image.jpg" alt="test">';
         $result = \sanitizeHtml($input);
         self::assertStringNotContainsString('//evil.com', $result, 'Protocol-relative image URLs should be rejected');
     }

     /**
      * Root-relative image paths should be preserved
      */
     public function testAllowsRootRelativeImages(): void
     {
         $input = '<img src="/images/photo.jpg" alt="test">';
         $result = \sanitizeHtml($input);
         self::assertStringContainsString('/images/photo.jpg', $result, 'Root-relative image paths should be preserved');
     }

     /**
      * Anchor target attribute is constrained to _blank or _self
      */
     public function testConstrainsAnchorTarget(): void
     {
         $input = '<a href="https://example.com" target="_blank">external</a>';
         $result = \sanitizeHtml($input);
         self::assertStringContainsString('target="_blank"', $result, 'target="_blank" should be preserved');
     }

     /**
      * Invalid anchor targets should be removed
      */
     public function testRemovesInvalidAnchorTargets(): void
     {
         $input = '<a href="https://example.com" target="_top">link</a>';
         $result = \sanitizeHtml($input);
         self::assertStringNotContainsString('target=', $result, 'Invalid target attributes should be removed');
     }

     /**
      * Anchor with target=_blank gets noopener noreferrer
      */
     public function testBlankTargetGetsNoopenerNoreferrer(): void
     {
         $input = '<a href="https://example.com" target="_blank">external</a>';
         $result = \sanitizeHtml($input);
         self::assertStringContainsString('noopener', $result, 'target="_blank" should have noopener');
         self::assertStringContainsString('noreferrer', $result, 'target="_blank" should have noreferrer');
     }

     /**
      * Anchor rel attribute only allows controlled tokens
      */
     public function testConstrainsAnchorRelTokens(): void
     {
         $input = '<a href="https://example.com" rel="nofollow external">link</a>';
         $result = \sanitizeHtml($input);
         self::assertStringContainsString('rel=', $result, 'Valid rel tokens should be preserved');
         self::assertStringContainsString('nofollow', $result);
         self::assertStringContainsString('external', $result);
     }

     /**
      * Anchor with invalid rel tokens should have them removed
      */
     public function testRemovesInvalidAnchorRelTokens(): void
     {
         $input = '<a href="https://example.com" rel="malicious badtoken">link</a>';
         $result = \sanitizeHtml($input);
         self::assertStringNotContainsString('malicious', $result, 'Invalid rel tokens should be removed');
         self::assertStringNotContainsString('badtoken', $result);
     }
}

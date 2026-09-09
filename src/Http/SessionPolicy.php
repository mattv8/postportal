<?php

declare(strict_types=1);

namespace PostPortal\Http;

/**
 * Session security policy configuration
 * SEC-09: Centralized session lifecycle defense
 *
 * Called before first session_start() to establish strict secure defaults:
 * - Strict mode: reject unknown session IDs
 * - HttpOnly: prevent JavaScript access
 * - SameSite=Lax: CSRF defense with safe cross-site navigation
 * - Secure: HTTPS only (when deployment is HTTPS)
 * - Host-only: cookie not sent to parent/sibling domains
 */
class SessionPolicy
{
    /**
     * Apply security policy before session start.
     * Derives Secure flag from explicit trusted APP_URL config, never from X-Forwarded-Proto.
     */
    public static function apply(): void
    {
        // Strict mode: reject any session ID not created by this server
        ini_set('session.use_strict_mode', '1');

        // HttpOnly: prevent JavaScript from accessing session cookie
        ini_set('session.cookie_httponly', '1');

        // SameSite=Lax: mitigate CSRF while allowing safe cross-site navigation
        ini_set('session.cookie_samesite', 'Lax');

        // Use only cookies (not URL rewriting)
        ini_set('session.use_only_cookies', '1');

        // Secure: only send over HTTPS when deployment is HTTPS
        // Derived from APP_URL if configured (Workstream 0 canonical URL)
        $appUrl = getenv('APP_URL') ?: '';
        if ($appUrl !== '' && strpos($appUrl, 'https://') === 0) {
            ini_set('session.cookie_secure', '1');
        }

        // Domain: restrict to exact host (no parent/sibling domains)
        // Empty string = current host only (most secure default)
        // nginx and other reverse proxies should ensure Host header is canonical
        ini_set('session.cookie_domain', '');
    }
}

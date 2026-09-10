<?php

declare(strict_types=1);

namespace PostPortal\Security;

final class AppKeyDeriver
{
    private const SMTP = 'postportal/smtp-encryption/v1';
    private const UNSUBSCRIBE = 'postportal/unsubscribe-signing/v1';
    private const LOGIN_THROTTLE = 'postportal/login-throttle-hmac/v1';
    private const NEWSLETTER_THROTTLE = 'postportal/newsletter-throttle-hmac/v1';

    public static function smtpEncryptionKey(?string $secretHex = null): string
    {
        return self::derive(self::SMTP, $secretHex);
    }

    public static function unsubscribeSigningKey(?string $secretHex = null): string
    {
        return self::derive(self::UNSUBSCRIBE, $secretHex);
    }

    public static function loginThrottleHmacKey(?string $secretHex = null): string
    {
        return self::derive(self::LOGIN_THROTTLE, $secretHex);
    }

    public static function newsletterThrottleHmacKey(?string $secretHex = null): string
    {
        return self::derive(self::NEWSLETTER_THROTTLE, $secretHex);
    }

    private static function derive(string $context, ?string $secretHex): string
    {
        $secretHex ??= getenv('APP_SECRET') ?: '';
        if (!preg_match('/\A[0-9a-fA-F]{64}\z/', $secretHex)) {
            throw new \RuntimeException('APP_SECRET must be exactly 64 hexadecimal characters');
        }

        $root = hex2bin($secretHex);
        if ($root === false) {
            throw new \RuntimeException('APP_SECRET is invalid');
        }

        return hash_hkdf('sha256', $root, 32, $context);
    }
}

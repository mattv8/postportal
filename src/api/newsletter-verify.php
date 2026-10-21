<?php

declare(strict_types=1);

use PostPortal\Container\ServiceContainer;
use PostPortal\Http\ViewRenderer;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../Http/ViewRenderer.php';

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('Referrer-Policy: strict-origin');
header('X-Content-Type-Options: nosniff');

/**
 * Initialize the stateless newsletter verification page.
 *
 * @return array{container: ServiceContainer, smarty: \Smarty\Smarty}
 */
function bootstrapVerify(): array
{
    require __DIR__ . '/../config.php';
    $db = getDefaultDbConnection();
    if (!$db instanceof \mysqli) {
        throw new \RuntimeException('Database connection failed');
    }

    $container = ServiceContainer::getInstance($db);
    $view = ViewRenderer::fromSmarty(null, false);
    $smarty = $view->getSmarty();
    $smarty->assign('settings', $container->getSettingsRepository()->getSettings());

    return ['container' => $container, 'smarty' => $smarty];
}

function isValidVerifyToken(string $token): bool
{
    return preg_match('/\A[a-f0-9]{64}\z/', $token) === 1;
}

function validateSameOrigin(): bool
{
    try {
        $appOrigin = originFromUrl(getCanonicalAppUrl());
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (is_string($origin) && $origin !== '' && $origin !== 'null') {
            return originFromUrl($origin) === $appOrigin;
        }

        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (is_string($referer) && $referer !== '') {
            return originFromUrl($referer) === $appOrigin;
        }

        return ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'same-origin';
    } catch (\Throwable) {
        return false;
    }
}

function originFromUrl(string $url): string
{
    $parts = parse_url($url);
    if ($parts === false || !isset($parts['scheme'], $parts['host']) || isset($parts['user'], $parts['pass'])) {
        throw new \InvalidArgumentException('Invalid origin URL');
    }

    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        throw new \InvalidArgumentException('Invalid origin scheme');
    }

    $origin = $scheme . '://' . strtolower($parts['host']);
    if (isset($parts['port']) && !($scheme === 'http' && $parts['port'] === 80) && !($scheme === 'https' && $parts['port'] === 443)) {
        $origin .= ':' . $parts['port'];
    }

    return $origin;
}

function requestToken(array $source): string
{
    $token = $source['token'] ?? '';

    return is_string($token) ? $token : '';
}

function renderVerifyError(\Smarty\Smarty $smarty, int $status = 400): never
{
    http_response_code($status);
    $smarty->assign('status', 'error');
    $smarty->display('newsletter_verify.tpl');
    exit;
}

function renderVerifyFallbackError(): never
{
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="referrer" content="no-referrer"><title>Verification Failed</title></head><body><p>This verification link is invalid or has expired.</p></body></html>';
    exit;
}

try {
    ['container' => $container, 'smarty' => $smarty] = bootstrapVerify();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $token = requestToken($_GET);
        if (!isValidVerifyToken($token)) {
            renderVerifyError($smarty);
        }

        $smarty->assign('status', 'confirm');
        $smarty->assign('token', $token);
        $smarty->display('newsletter_verify.tpl');
        exit;
    }

    if ($method === 'POST') {
        if (!validateSameOrigin()) {
            renderVerifyError($smarty);
        }

        $token = requestToken($_POST);
        if (!isValidVerifyToken($token)) {
            renderVerifyError($smarty);
        }

        $result = $container->getNewsletterService()->verifySubscriber($token);
        if ($result['success'] !== true) {
            renderVerifyError($smarty);
        }

        $smarty->assign('status', 'success');
        $smarty->display('newsletter_verify.tpl');
        exit;
    }

    header('Allow: GET, POST');
    renderVerifyError($smarty, 405);
} catch (\Throwable) {
    error_log('Newsletter verification failed');
    renderVerifyFallbackError();
}

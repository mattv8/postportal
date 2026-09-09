<?php

declare(strict_types=1);

use PostPortal\Container\ServiceContainer;
use PostPortal\Http\ViewRenderer;
use PostPortal\Http\SessionPolicy;

require_once __DIR__ . '/functions.php';

// Repositories
require_once __DIR__ . '/Repository/BaseRepository.php';
require_once __DIR__ . '/Repository/PostRepositoryInterface.php';
require_once __DIR__ . '/Repository/PostRepository.php';
require_once __DIR__ . '/Repository/MediaRepositoryInterface.php';
require_once __DIR__ . '/Repository/MediaRepository.php';
require_once __DIR__ . '/Repository/NewsletterRepositoryInterface.php';
require_once __DIR__ . '/Repository/NewsletterRepository.php';
require_once __DIR__ . '/Repository/SettingsRepositoryInterface.php';
require_once __DIR__ . '/Repository/SettingsRepository.php';

// Service Interfaces
require_once __DIR__ . '/Service/PostServiceInterface.php';
require_once __DIR__ . '/Service/MediaServiceInterface.php';
require_once __DIR__ . '/Service/NewsletterServiceInterface.php';

// Services
require_once __DIR__ . '/Service/PostService.php';
require_once __DIR__ . '/Service/MediaService.php';
require_once __DIR__ . '/Service/NewsletterService.php';

// Container and View
require_once __DIR__ . '/Container/ServiceContainer.php';
require_once __DIR__ . '/Http/SessionPolicy.php';
require_once __DIR__ . '/Http/ViewRenderer.php';

/**
 * Global exception handler for page-level requests.
 * Logs errors and displays a generic error page to the user.
 */
set_exception_handler(function (\Throwable $e): void {
    error_log('Uncaught exception: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ':' . $e->getLine() . ' | Trace: ' . $e->getTraceAsString());

    // Avoid sending headers if already sent
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }

    // Try to use Smarty template for error page
    try {
        $smarty = new \Smarty\Smarty();
        $smarty->setTemplateDir(__DIR__ . '/templates');
        $smarty->setCompileDir(__DIR__ . '/templates_c');
        $smarty->setCacheDir(__DIR__ . '/cache');
        $smarty->assign('page_title', 'Error');
        $smarty->assign('error_code', '500');
        $smarty->assign('error_title', 'Something went wrong');
        $smarty->assign('error_message', 'We encountered an unexpected error. Please try again later.');
        $smarty->display('error.tpl');
    } catch (\Throwable $templateError) {
        // Fallback to minimal HTML if template fails
        echo '<!DOCTYPE html><html><head><title>Error</title></head><body>';
        echo '<h1>Something went wrong</h1>';
        echo '<p>We encountered an unexpected error. Please try again later.</p>';
        echo '</body></html>';
    }
    exit;
});

/**
 * Bootstrap shared page context: session, auth, DB, DI, and view renderer.
 *
 * @return array{container: ServiceContainer, db: mysqli, view: ViewRenderer, config: array<string, mixed>}
 */
function bootstrapPageContext(bool $requireAuth = false, bool $requireAdmin = false): array
{
    // SEC-09: Apply session policy before first session_start
    SessionPolicy::apply();
    ensureSession();

    if ($requireAuth && (empty($_SESSION['authenticated']) || empty($_SESSION['username']))) {
        http_response_code(401);
        header('Location: /?page=login');
        exit;
    }

    // VAL-01: Fail-closed admin check - require explicit isadmin === true
    if ($requireAdmin && (!isset($_SESSION['isadmin']) || $_SESSION['isadmin'] !== true)) {
        http_response_code(403);
        echo 'You do not have access to this page.';
        exit;
    }

    require __DIR__ . '/config.php';
    $db = getDefaultDbConnection();
    $defaults = getAppDefaults();
    $authType = $defaults['auth_type'];
    $defaultPage = $defaults['default_page'];
    $defaultLogo = $defaults['default_logo'];
    if (!$db) {
        http_response_code(500);
        echo 'Database connection failed';
        exit;
    }

    $container = ServiceContainer::getInstance($db);
    $view = ViewRenderer::fromSmarty($smarty ?? null, (bool) ($smarty_debug ?? false));

    return [
        'container' => $container,
        'db' => $db,
        'view' => $view,
        'config' => [
            'default_admin_password' => $default_admin_password ?? '',
            'default_logo' => $defaultLogo,
            'default_page' => $defaultPage,
            'auth_type' => $authType,
        ],
    ];
}

<?php

declare(strict_types=1);

use PostPortal\Container\ServiceContainer;
use PostPortal\Http\SessionPolicy;
use PostPortal\Http\ViewRenderer;
use PostPortal\Page\AdminPage;
use PostPortal\Page\HomePage;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/Container/ServiceContainer.php';
require_once __DIR__ . '/Http/ViewRenderer.php';
require_once __DIR__ . '/page_bootstrap.php';
require_once __DIR__ . '/Page/HomePage.php';
require_once __DIR__ . '/Page/AdminPage.php';
require_once __DIR__ . '/config.php';

// Defaults (centralized)
$defaults = getAppDefaults();
$authType = $defaults['auth_type'];
$defaultPage = $defaults['default_page'];
$defaultLogo = $defaults['default_logo'];
$jsConfig = $defaults['js_config'];

// Fixed route map: only allow known routes before any data construction.
$allowedRoutes = ['home', 'post', 'login', 'admin'];
$requestedPage = $_GET['page'] ?? $defaultPage;

// Reject unknown routes with 404 before building page data.
if (!in_array($requestedPage, $allowedRoutes, true)) {
	http_response_code(404);
	echo 'Not found';
	exit;
}

$page = $requestedPage;

// Only start session if:
// 1. User already has a session cookie (returning/authenticated user), OR
// 2. User is accessing login or admin pages (need session for auth)
// This allows anonymous home page visits to be cached by nginx
$needsSession = isset($_COOKIE[session_name()]) || in_array($page, ['login', 'admin'], true);
if ($needsSession) {
    SessionPolicy::apply();
    ensureSession();
}

$isAuthenticated = $needsSession && !empty($_SESSION['authenticated']);
$isAdmin = $needsSession && ($_SESSION['isadmin'] ?? null) === true;

// Build common services.
$db = getDefaultDbConnection();
if (!$db) {
	http_response_code(500);
	echo 'Database connection failed';
	exit;
}

$container = ServiceContainer::getInstance($db);
$view = ViewRenderer::fromSmarty($smarty ?? null, (bool) ($smarty_debug ?? false));

$baseData = [
	'page' => $page,
	'auth_type' => $authType,
	'authenticated' => $isAuthenticated,
	'isadmin' => $isAdmin,
	'currentUser' => $needsSession ? ($_SESSION['username'] ?? null) : null,
	'displayname' => $needsSession ? ($_SESSION['displayname'] ?? ($_SESSION['username'] ?? '')) : '',
	'default_page' => $defaultPage,
	'default_admin_password' => $default_admin_password ?? '',
	'js_config' => $jsConfig,
	'debug' => $debug ?? false,
	'page_bg_color_class' => $page_bg_color_class ?? '',
];

// Generate CSRF token when authenticated so templates can pick it up.
if ($isAuthenticated) {
	$baseData['csrf_token'] = generateCsrfToken();
}

// Decide which page builder to use.
$pageData = [];
switch ($page) {
	case 'admin':
		if (!$isAuthenticated || !$isAdmin) {
			header('Location: /?page=login');
			exit;
		}

		$adminPage = new AdminPage($container, $db, $defaultLogo);
		$pageData = $adminPage->build();
		break;

	case 'login':
		// Keep login lean; settings ensure consistent branding on the login screen.
		$settings = getSettings($db);
		$logoUrls = getLogoUrls($db, $settings['logo_media_id'] ?? null, $defaultLogo);
		$faviconUrls = getFaviconUrls($db, $settings['favicon_media_id'] ?? null);
		$pageData = [
			'settings' => $settings,
			'logo_url' => $logoUrls['logo_url'],
			'logo_srcset_png' => $logoUrls['logo_srcset_png'],
			'logo_srcset_webp' => $logoUrls['logo_srcset_webp'],
			'favicon_ico' => $faviconUrls['favicon_ico'],
			'favicon_svg' => $faviconUrls['favicon_svg'],
			'favicon_16' => $faviconUrls['favicon_16'],
			'favicon_32' => $faviconUrls['favicon_32'],
			'favicon_192' => $faviconUrls['favicon_192'],
			'favicon_512' => $faviconUrls['favicon_512'],
			'msg_login' => 'Login',
			'msg_username' => 'Username',
			'msg_password' => 'Password',
			'msg_submit' => 'Submit',
			'msg_logout' => 'Logout',
		];
		break;

	case 'home':
	case 'post':
	default:
		$homePage = new HomePage($container, $db, $defaultLogo);
		$pageData = $homePage->build();
		break;
}

// Render the page using the unified template.
$view->assign(array_merge($baseData, $pageData));
$view->getSmarty()->display('index.tpl');

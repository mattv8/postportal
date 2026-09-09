<?php

declare(strict_types=1);

use PostPortal\Http\ErrorResponse;
use PostPortal\Http\SessionPolicy;

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');
SessionPolicy::apply();
ensureSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ErrorResponse::json(405, 'Method not allowed');
}

// SEC-09: Require CSRF token for logout to prevent cross-site logout attacks
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
if (!validateCsrfToken($token)) {
    // Return 419 Conflict, preserve session on CSRF failure
    ErrorResponse::json(419, 'CSRF validation failed');
}

// Clear session data
$_SESSION = [];

// Clear session cookie using centralized parameters
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

echo json_encode(['success' => true]);

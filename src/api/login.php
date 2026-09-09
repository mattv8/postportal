<?php

declare(strict_types=1);

use PostPortal\Http\ApiHandler;
use PostPortal\Http\ErrorResponse;

require_once __DIR__ . '/bootstrap.php';

/**
 * Verify reCAPTCHA token with Google's API
 */
function verifyRecaptchaToken(string $token, string $secret): bool
{
    if ($secret === '') {
        return true; // reCAPTCHA not configured
    }

    if ($token === '') {
        return false;
    }

    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    if ($ch === false) {
        return false;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'secret' => $secret,
            'response' => $token,
        ]),
        CURLOPT_TIMEOUT => 5,
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        curl_close($ch);
        return false;
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) {
        return false;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) && isValidRecaptchaLoginAssessment($decoded);
}

ApiHandler::handle(function (): void {
    // Login doesn't require auth, but we need the DB connection and services
    ['container' => $container, 'db' => $db] = bootstrapApi();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ErrorResponse::json(405, 'Method not allowed');
    }

    $input = readJsonInput();
    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $recaptchaToken = (string)($input['recaptcha_token'] ?? '');

    if ($username === '' || $password === '') {
        ErrorResponse::badRequest('Username and password are required.');
    }

    // SEC-10: Server-side login throttling - check BEFORE password verification
    $userRepository = $container->getUserRepository();

    // Create throttle scope keys: source IP and normalized username
    // Repository will HMAC these; we pass raw values for privacy
    $sourceIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // Note: X-Forwarded-For trust depends on deployment topology (reverse proxy)
    // Workstream 0 should handle canonical APP_URL and proxy configuration

    $throttleCheck = $userRepository->checkLoginThrottle($sourceIp, strtolower($username));
    if (!$throttleCheck['allowed']) {
        $retryAfter = $throttleCheck['retry_after'] ?? 60;
        header('Retry-After: ' . $retryAfter);
        ErrorResponse::json(429, 'Too many login attempts. Please try again later.');
    }

    // Validate reCAPTCHA (if configured)
    $recaptchaSecret = getenv('RECAPTCHA_SECRET_KEY') ?: '';
    if (!verifyRecaptchaToken($recaptchaToken, $recaptchaSecret)) {
        ErrorResponse::badRequest('reCAPTCHA validation failed.');
    }

    // Query user from database
    $stmt = mysqli_prepare($db, 'SELECT username, password, first, last, isadmin, active FROM users WHERE username = ? LIMIT 1');
    if (!$stmt) {
        ErrorResponse::internalError('Failed to prepare query');
    }

    mysqli_stmt_bind_param($stmt, 's', $username);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = $result ? mysqli_fetch_assoc($result) : null;

    // Generic response for both nonexistent and wrong password (don't reveal account existence)
    if (!$user || (int)$user['active'] !== 1 || !password_verify($password, (string)$user['password'])) {
        // Record failed attempt for throttling (generic response for all failures)
        $userRepository->recordLoginAttempt($sourceIp, strtolower($username));
        ErrorResponse::unauthorized('Invalid username or password.');
    }

    // Clear throttle records after successful login
    $userRepository->clearLoginAttempts($sourceIp, strtolower($username));

    // SEC-09: Session rotation - regenerate ID with deletion of old session
    // This prevents session fixation attacks
    session_regenerate_id(true);

    // Set session flags - VAL-01: Fail-closed admin check (explicit true required)
    $_SESSION['authenticated'] = true;
    $_SESSION['username'] = $user['username'];
    $_SESSION['isadmin'] = (bool)($user['isadmin'] ?? false);
    $_SESSION['displayname'] = trim(($user['first'] ?? '') . ' ' . ($user['last'] ?? '')) ?: $user['username'];

    ErrorResponse::success([
        'username' => $user['username'],
        'isadmin' => $_SESSION['isadmin'],
    ]);
});

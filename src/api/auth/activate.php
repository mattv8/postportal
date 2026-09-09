<?php

declare(strict_types=1);

use PostPortal\Http\ApiHandler;
use PostPortal\Http\ErrorResponse;
use PostPortal\Http\SessionPolicy;

require_once __DIR__ . '/../bootstrap.php';

/**
 * POST /api/auth/activate.php
 * SEC-11: Public account activation endpoint
 *
 * Canonical URL contract for Task 6 (Workstream 5):
 *   GET: /?page=login&activate=1&username=<urlencoded>&token=<urlencoded>
 *   POST: /api/auth/activate.php with JSON body
 *
 * Redeems single-use activation token and sets user-chosen password.
 * Atomically validates token, consumes it, and activates user.
 * Never reveals whether account exists.
 *
 * Request:
 *   POST JSON: {
 *     "username": "newuser",
 *     "token": "0a1b2c3d...",  // raw token (64 hex chars)
 *     "password": "newpassword123",
 *     "password_confirm": "newpassword123"
 *   }
 *
 * Response (200 OK):
 *   {"success": true}
 *   + Session established (if not already authenticated)
 *
 * Response (400/401):
 *   {"success": false, "error": "Invalid request"}
 *   (generic; never reveals account/token existence)
 */
ApiHandler::handle(function (): void {
    // Activation is public; no authentication required
    ['container' => $container, 'db' => $db] = bootstrapApi();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ErrorResponse::json(405, 'Method not allowed');
    }

    $input = readJsonInput();
    $username = trim((string)($input['username'] ?? ''));
    $token = trim((string)($input['token'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $passwordConfirm = (string)($input['password_confirm'] ?? '');

    // Validation: generic response for all errors (never reveal account existence)
    if ($username === '' || $token === '' || $password === '') {
        ErrorResponse::badRequest('Invalid request');
    }

    // Validate username format (prevent absurd inputs)
    if (!preg_match('/^[a-zA-Z0-9_.-]{1,32}$/', $username)) {
        ErrorResponse::badRequest('Invalid request');
    }

    // Validate token format (must be 64 hex chars = 32 random bytes)
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
        ErrorResponse::badRequest('Invalid request');
    }

    // Validate password strength (min 8 chars, mix of case/digit)
    if (strlen($password) < 8 || !preg_match('/[a-z]/i', $password) || !preg_match('/[0-9]/', $password)) {
        ErrorResponse::badRequest('Password must be at least 8 characters with letters and numbers');
    }

    // Confirm password matches
    if ($password !== $passwordConfirm) {
        ErrorResponse::badRequest('Invalid request');
    }

    // Redeem token atomically
    $userRepository = $container->getUserRepository();
    $result = $userRepository->redeemActivationToken($username, $token, $password);

    if (!$result['success']) {
        // Generic error; never reveal account/token status
        ErrorResponse::unauthorized('Invalid request');
    }

    // Activation successful
    // Rotate session to bind to new authenticated state
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
        // If user was already in session, update their active state
        if (!empty($_SESSION['username']) && $_SESSION['username'] === $username) {
            $_SESSION['active'] = 1;
        }
    }

    ErrorResponse::success(['message' => 'Account activated successfully.']);
});

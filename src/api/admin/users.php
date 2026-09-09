<?php

declare(strict_types=1);

use PostPortal\Container\ServiceContainer;
use PostPortal\Http\ApiHandler;
use PostPortal\Http\ErrorResponse;
use PostPortal\Http\ViewRenderer;

require_once __DIR__ . '/../bootstrap.php';

/**
 * Send welcome email to newly created user with activation link
 *
 * @param ServiceContainer $container
 * @param array<string, mixed> $userData
 * @param string $activationUrl Canonical URL with activation token
 * @return array{success: bool, error?: string}
 */
function sendWelcomeEmail(ServiceContainer $container, array $userData, string $activationUrl): array
{
    try {
        require_once __DIR__ . '/../../functions.php';
        $siteUrl = getCanonicalAppUrl();
        $username = trim((string)($userData['username'] ?? ''));
        $activationParts = parse_url($activationUrl);
        $activationQuery = [];
        if (is_array($activationParts)) {
            parse_str((string)($activationParts['query'] ?? ''), $activationQuery);
        }
        $expectedActivationUrl = $siteUrl . '/?' . http_build_query([
            'page' => 'login',
            'activate' => '1',
            'username' => $username,
            'token' => (string)($activationQuery['token'] ?? ''),
        ], '', '&', PHP_QUERY_RFC3986);
        if (!is_array($activationParts)
            || !isset($activationParts['scheme'], $activationParts['host'])
            || !in_array(strtolower($activationParts['scheme']), ['http', 'https'], true)
            || !is_array($activationQuery)
            || !isset($activationQuery['page'], $activationQuery['activate'], $activationQuery['username'], $activationQuery['token'])
            || count($activationQuery) !== 4
            || $activationQuery['page'] !== 'login'
            || $activationQuery['activate'] !== '1'
            || $activationQuery['username'] !== $username
            || !is_string($activationQuery['token'])
            || preg_match('/\A[a-f0-9]{64}\z/', $activationQuery['token']) !== 1
            || !hash_equals($expectedActivationUrl, $activationUrl)) {
            error_log('Welcome activation link validation failed');
            return ['success' => false, 'error' => 'Unable to send welcome email.'];
        }

        $settingsRepository = $container->getSettingsRepository();
        $settings = $settingsRepository->getSettings() ?? [];

        // Check SMTP configuration
        $smtpHost = $settings['smtp_host'] ?? '';
        if (empty($smtpHost)) {
            return [
                'success' => false,
                'error' => 'Unable to send welcome email.',
            ];
        }

        $smtpPort = (int)($settings['smtp_port'] ?? 587);
        $smtpSecure = $settings['smtp_secure'] ?? 'none';
        $smtpAuth = (bool)($settings['smtp_auth'] ?? true);
        $smtpUsername = $settings['smtp_username'] ?? '';
        $smtpPassword = $settings['smtp_password'] ?? '';
        $smtpFromEmail = $settings['smtp_from_email'] ?? '';
        $smtpFromName = $settings['smtp_from_name'] ?? 'Post Portal';

        // SEC-08: Validate SMTP security policy before credential transmission
        if (!validateSmtpSecurityPolicy($smtpAuth, $smtpSecure)) {
            return [
                'success' => false,
                'error' => 'Unable to send welcome email.',
            ];
        }

        if (empty($smtpFromEmail)) {
            return [
                'success' => false,
                'error' => 'Unable to send welcome email.',
            ];
        }

        // Decrypt SMTP password if needed
        if (!empty($smtpPassword)) {
            $smtpPassword = decryptSmtpPassword($smtpPassword);
        }

        if ($smtpSecure === 'none') {
            $smtpSecure = '';
        }

        $userEmail = $userData['email'] ?? '';
        $firstName = $userData['first'] ?? '';
        $lastName = $userData['last'] ?? '';
        $displayName = trim($firstName . ' ' . $lastName) ?: $username;

        if (empty($userEmail)) {
            return [
                'success' => false,
                'error' => 'Unable to send welcome email.',
            ];
        }

        // Get site info using canonical URL
        $siteTitle = $settings['site_title'] ?? 'Post Portal';

        // Build email body using Smarty template
        $emailView = ViewRenderer::fromSmarty(null, false)->getSmarty();
        $emailView->assign('site_title', $siteTitle);
        $emailView->assign('site_url', $siteUrl);
        $emailView->assign('display_name', $displayName);
        $emailView->assign('username', $username);
        $emailView->assign('activation_url', $activationUrl);

        $emailBody = $emailView->fetch('email_welcome.tpl');
        $emailAltBody = $emailView->fetch('email_welcome_text.tpl');

        require_once __DIR__ . '/../../vendor/autoload.php';

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        // Debug settings
        $debugMode = getenv('DEBUG') === 'true' || getenv('DEBUG') === '1';
        $mail->SMTPDebug = $debugMode ? 3 : 0;
        $mail->Debugoutput = function ($str, $level) use ($debugMode): void {
            if ($debugMode) {
                $logFile = getSmtpLogPath();
                $redacted = redactSmtpDiagnostics($str);
                @file_put_contents($logFile, date('Y-m-d H:i:s') . ' - ' . $redacted . "\n", FILE_APPEND);
            }
        };

        // Server settings
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->Port = $smtpPort;
        configureSmtpTransportSecurity($mail, 10);

        if ($smtpAuth) {
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUsername;
            $mail->Password = $smtpPassword;
        } else {
            $mail->SMTPAuth = false;
        }

        if ($smtpSecure) {
            $mail->SMTPSecure = $smtpSecure;
        }

        // Sender and recipient
        $mail->setFrom($smtpFromEmail, $smtpFromName);
        $mail->addAddress($userEmail, $displayName);

        // Content
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = "Your account has been created - {$siteTitle}";
        $mail->Body = $emailBody;
        $mail->AltBody = $emailAltBody;

        $mail->send();

        return ['success' => true];
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        $msg = $e->getMessage();
        $redacted = redactSmtpDiagnostics($msg);
        error_log('Welcome email PHPMailer error: ' . $redacted);
        return [
            'success' => false,
            'error' => 'Unable to send welcome email.',
        ];
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        $redacted = redactSmtpDiagnostics($msg);
        error_log('Welcome email error: ' . $redacted);
        return [
            'success' => false,
            'error' => 'Unable to send welcome email.',
        ];
    }
}

ApiHandler::handle(function (): void {
    ['container' => $container] = bootstrapApi(requireAuth: true, requireAdmin: true);
    $userService = $container->getUserService();

    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            // Get all users or single user
            if (isset($_GET['username'])) {
                $username = trim((string)$_GET['username']);
                $result = $userService->getUser($username);
                if ($result['success']) {
                    ErrorResponse::success(['data' => $result['data']]);
                }
                ErrorResponse::notFound($result['error'] ?? 'User not found');
            }

            $result = $userService->getAllUsers();
            if ($result['success']) {
                ErrorResponse::success([
                    'data' => $result['data'],
                    'count' => count($result['data']),
                ]);
            }
            ErrorResponse::internalError($result['error'] ?? 'Failed to get users');
            // phpcs:ignore -- switch exhaustiveness handled by never-returning ErrorResponse

        case 'POST':
            requireCsrfToken();
            $payload = readJsonInput();

              $sendEmail = isset($payload['send_email']) && $payload['send_email'] === true;
              $payload['username'] = trim((string)($payload['username'] ?? ''));
              if ($sendEmail) {
                  unset($payload['password'], $payload['confirm_password']);
              }

             $result = $userService->createUser($payload);
             if ($result['success']) {
                 $response = ['message' => $result['message'] ?? 'User created successfully'];

                 // Send welcome email with activation link if requested
                 if ($sendEmail) {
                      $username = $payload['username'];

                     // SEC-11: Issue activation token instead of using plaintext password
                     $tokenResult = $userService->issueActivationToken($username);

                      if ($tokenResult['success'] && isset($tokenResult['token'])
                          && preg_match('/\A[a-f0-9]{64}\z/', $tokenResult['token']) === 1) {
                          $token = $tokenResult['token'];

                         // Build canonical activation URL
                         try {
                             require_once __DIR__ . '/../../functions.php';
                              $activationUrl = getCanonicalAppUrl() . '/?' . http_build_query([
                                  'page' => 'login',
                                  'activate' => '1',
                                  'username' => $username,
                                  'token' => $token,
                              ], '', '&', PHP_QUERY_RFC3986);

                             $emailResult = sendWelcomeEmail($container, $payload, $activationUrl);
                             $response['email_sent'] = $emailResult['success'];
                             if (!$emailResult['success']) {
                                  $response['email_error'] = 'Unable to send welcome email.';
                             }
                         } catch (\RuntimeException $e) {
                             $response['email_sent'] = false;
                              $response['email_error'] = 'Unable to send welcome email.';
                              error_log('Activation URL generation failed');
                         }
                     } else {
                         $response['email_sent'] = false;
                          $response['email_error'] = 'Unable to send welcome email.';
                          error_log('Activation token issuance failed');
                     }
                 }

                 ErrorResponse::success($response);
             }
             ErrorResponse::unprocessableEntity($result['error'] ?? 'Failed to create user');

        case 'PUT':
            requireCsrfToken();
            $payload = readJsonInput();

            $username = trim((string)($payload['username'] ?? ''));
            if ($username === '') {
                ErrorResponse::badRequest('Username is required');
            }

            // Check if this is a single field update or full update
            if (isset($payload['field']) && isset($payload['value'])) {
                // Single field update
                $field = trim((string)$payload['field']);
                $value = $payload['value'];

                $result = $userService->updateUserAttribute($username, $field, $value);
            } else {
                // Full update - remove username from data
                unset($payload['username']);
                $result = $userService->updateUser($username, $payload);
            }

            if ($result['success']) {
                ErrorResponse::success(['message' => $result['message'] ?? 'User updated successfully']);
            }
            ErrorResponse::unprocessableEntity($result['error'] ?? 'Failed to update user');

        case 'DELETE':
            requireCsrfToken();
            $username = isset($_GET['username']) ? trim((string)$_GET['username']) : '';

            if ($username === '') {
                ErrorResponse::badRequest('Username is required');
            }

            $currentUser = $_SESSION['username'] ?? '';
            $result = $userService->deleteUser($username, $currentUser);

            if ($result['success']) {
                ErrorResponse::success(['message' => $result['message'] ?? 'User deleted successfully']);
            }
            ErrorResponse::unprocessableEntity($result['error'] ?? 'Failed to delete user');

        default:
            ErrorResponse::json(405, 'Method not allowed');
    }
});

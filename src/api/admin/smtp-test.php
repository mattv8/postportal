<?php

declare(strict_types=1);

/**
 * SMTP Test API
 * Tests SMTP configuration and sends test emails
 */
use PostPortal\Http\ApiHandler;
use PostPortal\Http\ErrorResponse;

require_once __DIR__ . '/../bootstrap.php';

ApiHandler::handle(function (): void {
    ['container' => $container] = bootstrapApi(requireAuth: true, requireAdmin: true);
    $settingsRepository = $container->getSettingsRepository();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ErrorResponse::json(405, 'Method not allowed');
    }

    requireCsrfToken();
    $payload = readJsonInput();
    if (empty($payload)) {
        ErrorResponse::badRequest('Invalid request payload');
    }

    $action = $payload['action'] ?? 'test';
    $settings = $settingsRepository->getSettings() ?? [];

    // Decrypt stored SMTP password if present
    if (!empty($settings['smtp_password'])) {
        $settings['smtp_password'] = decryptSmtpPassword($settings['smtp_password']);
    }

    $smtpConfig = [];
    if (isset($payload['smtp_host'])) {
        $smtpConfig = [
            'host' => $payload['smtp_host'],
            'port' => isset($payload['smtp_port']) ? (int) $payload['smtp_port'] : 587,
            'secure' => $payload['smtp_secure'] ?? 'none',
            'auth' => isset($payload['smtp_auth']) ? filter_var($payload['smtp_auth'], FILTER_VALIDATE_BOOLEAN) : true,
            'username' => $payload['smtp_username'] ?? '',
            'password' => !empty($payload['smtp_password']) ? (string) $payload['smtp_password'] : ($settings['smtp_password'] ?? ''),
            'from_email' => $payload['smtp_from_email'] ?? '',
            'from_name' => $payload['smtp_from_name'] ?? 'Post Portal',
        ];
    } else {
        $smtpConfig = [
            'host' => $settings['smtp_host'] ?? '',
            'port' => isset($settings['smtp_port']) ? (int) $settings['smtp_port'] : 587,
            'secure' => $settings['smtp_secure'] ?? 'none',
            'auth' => isset($settings['smtp_auth']) ? (bool) $settings['smtp_auth'] : true,
            'username' => $settings['smtp_username'] ?? '',
            'password' => $settings['smtp_password'] ?? '',
            'from_email' => $settings['smtp_from_email'] ?? '',
            'from_name' => $settings['smtp_from_name'] ?? 'Post Portal',
        ];
    }

    if ($smtpConfig['secure'] === 'none') {
        $smtpConfig['secure'] = '';
    }

    if (empty($smtpConfig['host'])) {
        ErrorResponse::badRequest('SMTP host is required');
    }

    if (empty($smtpConfig['from_email'])) {
        ErrorResponse::badRequest('From email is required');
    }

    // SEC-08: Validate SMTP security policy before credential transmission
    if (!validateSmtpSecurityPolicy($smtpConfig['auth'], $smtpConfig['secure'])) {
        ErrorResponse::badRequest('SMTP authentication requires encryption (TLS or SSL). Plaintext authentication is not allowed.');
    }

    $testEmail = $payload['test_email'] ?? '';
    if ($action === 'test') {
        if (empty($testEmail)) {
            ErrorResponse::badRequest('Test email address is required');
        }
        if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            ErrorResponse::badRequest('Invalid test email address');
        }
    }

    $debugMode = getenv('DEBUG') === 'true' || getenv('DEBUG') === '1';

    try {
        require_once __DIR__ . '/../../vendor/autoload.php';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $timeoutSeconds = 10; // Shorten SMTP connect/send timeout so failures return quickly
        @set_time_limit($timeoutSeconds + 5);
        $mail->SMTPDebug = $debugMode ? 3 : 0;
        $mail->Debugoutput = function ($str, $level) use ($debugMode): void {
            if ($debugMode) {
                $logFile = getSmtpLogPath();
                $redacted = redactSmtpDiagnostics($str);
                @file_put_contents($logFile, date('Y-m-d H:i:s') . ' - ' . $redacted . "\n", FILE_APPEND);
            }
        };

        $mail->isSMTP();
        configureSmtpTransportSecurity($mail, $timeoutSeconds);
        $mail->SMTPKeepAlive = false;
        $mail->Host = $smtpConfig['host'];
        $mail->Port = $smtpConfig['port'];

        if ($smtpConfig['auth']) {
            $mail->SMTPAuth = true;
            $mail->Username = $smtpConfig['username'];
            $mail->Password = $smtpConfig['password'];
        } else {
            $mail->SMTPAuth = false;
        }

        if ($smtpConfig['secure']) {
            $mail->SMTPSecure = $smtpConfig['secure'];
        }

        $mail->setFrom($smtpConfig['from_email'], $smtpConfig['from_name']);

        if ($action === 'test') {
            $mail->addAddress($testEmail);
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = 'SMTP Test Email from Post Portal';
            $mail->Body = '
                <html>
                <head>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background-color: #f8f9fa; padding: 20px; text-align: center; border-radius: 5px; margin-bottom: 20px; }
                        .header h1 { margin: 0; color: #0066cc; }
                        .content { background-color: #ffffff; padding: 20px; border: 1px solid #dee2e6; border-radius: 5px; }
                        .info { background-color: #e7f3ff; padding: 15px; border-left: 4px solid #0066cc; margin: 20px 0; }
                        .footer { text-align: center; color: #6c757d; font-size: 12px; margin-top: 20px; }
                    </style>
                </head>
                <body>
                    <div class="container">
                        <div class="header">
                            <h1>✓ SMTP Test Successful</h1>
                        </div>
                        <div class="content">
                            <p>This is a test email from your Post Portal SMTP configuration.</p>

                            <div class="info">
                                <strong>Configuration Details:</strong><br>
                                SMTP Host: ' . htmlspecialchars($smtpConfig['host']) . '<br>
                                SMTP Port: ' . htmlspecialchars((string) $smtpConfig['port']) . '<br>
                                Encryption: ' . htmlspecialchars($smtpConfig['secure'] ?: 'None') . '<br>
                                Authentication: ' . ($smtpConfig['auth'] ? 'Enabled' : 'Disabled') . '<br>
                                From Address: ' . htmlspecialchars($smtpConfig['from_email']) . '
                            </div>

                            <p>If you received this email, your SMTP configuration is working correctly!</p>
                        </div>
                        <div class="footer">
                            Sent: ' . date('Y-m-d H:i:s T') . '
                        </div>
                    </div>
                </body>
                </html>
            ';
            $mail->AltBody = 'This is a test email from your Post Portal SMTP configuration. If you received this email, your SMTP configuration is working correctly!';

            $mail->send();

            ErrorResponse::success([
                'message' => 'Test email sent successfully',
            ]);
        }

        $mail->SMTPDebug = 2;
        if (!$mail->smtpConnect()) {
            throw new Exception('Could not connect to SMTP server');
        }

        $mail->smtpClose();

        ErrorResponse::success([
            'message' => 'SMTP connection successful',
        ]);
    } catch (Throwable $e) {
        if ($debugMode) {
            @file_put_contents(
                getSmtpLogPath(),
                date('Y-m-d H:i:s') . ' - SMTP test failed: ' . redactSmtpDiagnostics($e->getMessage()) . "\n",
                FILE_APPEND
            );
        }

        ErrorResponse::json(500, 'SMTP connection or configuration failed. Please verify your settings and try again.');
    }
});

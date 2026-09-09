<?php

declare(strict_types=1);

namespace PostPortal\Service;

use PostPortal\Repository\SettingsRepository;
use Smarty\Smarty;
use Exception;

/**
 * Real newsletter verification mailer using PHPMailer
 *
 * Sends verification emails synchronously with SMTP configuration validation.
 * Never exposes raw tokens, credentials, or SMTP details in logs.
 */
class NewsletterVerificationMailer implements NewsletterVerificationMailerInterface
{
    private SettingsRepository $settingsRepository;
    private Smarty $smarty;
    /**
     * @var callable|null Test/mock transport for injecting fake PHPMailer behavior
     */
    private $testTransport;

    public function __construct(SettingsRepository $settingsRepository, Smarty $smarty, ?callable $testTransport = null)
    {
        $this->settingsRepository = $settingsRepository;
        $this->smarty = $smarty;
        $this->testTransport = $testTransport;
    }

    /**
     * @inheritDoc
     */
    public function sendVerificationEmail(string $email, string $rawToken): bool
    {
        try {
            // Fetch SMTP configuration
            $settings = $this->settingsRepository->getSettings() ?? [];

            // Validate SMTP security policy before assigning credentials
            $smtpHost = $settings['smtp_host'] ?? '';
            $smtpPort = isset($settings['smtp_port']) ? (int) $settings['smtp_port'] : 587;
            $smtpSecure = $settings['smtp_secure'] ?? 'none';
            $smtpAuth = isset($settings['smtp_auth']) ? (bool) $settings['smtp_auth'] : true;

            // Normalize SMTP secure: 'none' means no encryption
            if ($smtpSecure === 'none') {
                $smtpSecure = '';
            }
            $smtpUsername = $settings['smtp_username'] ?? '';
            $smtpPassword = $settings['smtp_password'] ?? '';
            $fromEmail = $settings['smtp_from_email'] ?? '';
            $fromName = $settings['smtp_from_name'] ?? 'Post Portal';

            // SEC-08: Reject authenticated plaintext SMTP before credential transmission
            if (!validateSmtpSecurityPolicy($smtpAuth, $smtpSecure)) {
                throw new \RuntimeException('SMTP security policy violation: authenticated plaintext not allowed');
            }

            if (empty($smtpHost)) {
                throw new \RuntimeException('SMTP host not configured');
            }

            if (empty($fromEmail)) {
                throw new \RuntimeException('SMTP from email not configured');
            }

            // Decrypt stored SMTP password if present
            if (!empty($smtpPassword) && strlen($smtpPassword) > 0) {
                try {
                    $smtpPassword = decryptSmtpPassword($smtpPassword);
                } catch (Exception $e) {
                    throw new \RuntimeException('Failed to decrypt SMTP password');
                }
            }

            // Build verification URL with canonical base
            $baseUrl = getCanonicalAppUrl();
            $urlEncodedToken = urlencode($rawToken);
            $verificationUrl = $baseUrl . '/api/newsletter-verify.php?token=' . $urlEncodedToken;

            // Render email templates with context escaping
            $this->smarty->assign('email', $email);
            $this->smarty->assign('verification_url', $verificationUrl);
            $this->smarty->assign('token_expiry_hours', 24);
            $this->smarty->assign('site_title', getenv('SITE_TITLE') ?: 'Post Portal');

            $htmlBody = $this->smarty->fetch('email_newsletter_verification.tpl');
            $textBody = $this->smarty->fetch('email_newsletter_verification_text.tpl');

            if (empty($htmlBody) || empty($textBody)) {
                throw new \RuntimeException('Failed to render verification email templates');
            }

            // Create PHPMailer instance
            require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

            if ($this->testTransport !== null) {
                // Test mode: use injected mock transport
                $result = ($this->testTransport)($htmlBody, $textBody, $email, $verificationUrl);
                return $result === true;
            }

            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

            // Configure SMTP connection with secure defaults
            $mail->isSMTP();
            configureSmtpTransportSecurity($mail, 10);
            $mail->Host = $smtpHost;
            $mail->Port = $smtpPort;

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

            // Set debug callback to log with redaction
            $debugMode = getenv('DEBUG') === 'true' || getenv('DEBUG') === '1';
            $mail->SMTPDebug = $debugMode ? 2 : 0;
            $mail->Debugoutput = function (string $str, int $level) use ($debugMode): void {
                if ($debugMode) {
                    $logFile = getSmtpLogPath();
                    $redacted = redactSmtpDiagnostics($str);
                    @file_put_contents($logFile, date('Y-m-d H:i:s') . ' [newsletter-verify] - ' . $redacted . "\n", FILE_APPEND);
                }
            };

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = 'Verify Your Newsletter Subscription';
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody;

            $mail->send();

            return true;
        } catch (\RuntimeException $e) {
            // Configuration errors are rethrown (not caught here)
            throw $e;
        } catch (Exception $e) {
            // Log error with redaction (never expose raw token, email, or credentials)
            $redacted = redactSmtpDiagnostics($e->getMessage());
            error_log('Newsletter verification email failed: ' . $redacted);

            // Return generic error to caller (transient SMTP/connection failures)
            return false;
        }
    }
}

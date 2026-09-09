<?php

declare(strict_types=1);

namespace PostPortal\Tests\Security;

use PHPUnit\Framework\TestCase;

final class SmtpSecurityTest extends TestCase
{
    private static string $temporaryDirectory;
    private string $mailerOutput = '';
    /** @var list<resource> */
    private array $processes = [];

    public static function setUpBeforeClass(): void
    {
        $legacyLibrary = dirname(__DIR__, 2) . '/src/lib/MediaProcessor.php';
        if (!is_file($legacyLibrary)) {
            mkdir(dirname($legacyLibrary), 0755, true);
            copy(dirname(__DIR__, 2) . '/src/Lib/MediaProcessor.php', $legacyLibrary);
        }
        self::$temporaryDirectory = sys_get_temp_dir() . '/smtp-security-' . bin2hex(random_bytes(6));
        mkdir(self::$temporaryDirectory, 0700, true);
        self::command('openssl req -x509 -newkey rsa:2048 -nodes -keyout ca.key -out ca.pem -subj /CN=SMTP-Test-CA -days 2', self::$temporaryDirectory);
        self::certificate('trusted', 'localhost', 'ca.pem', 'ca.key');
        self::certificate('wrong-host', 'wrong.invalid', 'ca.pem', 'ca.key');
        self::command('openssl req -x509 -newkey rsa:2048 -nodes -keyout self-signed.key -out self-signed.pem -subj /CN=localhost -days 2', self::$temporaryDirectory);
        self::command('openssl req -x509 -newkey rsa:2048 -nodes -keyout other-ca.key -out other-ca.pem -subj /CN=Other-CA -days 2', self::$temporaryDirectory);
        self::certificate('untrusted', 'localhost', 'other-ca.pem', 'other-ca.key');
        self::certificate('expired', 'localhost', 'ca.pem', 'ca.key', 0);
        sleep(1);
    }

    public static function tearDownAfterClass(): void
    {
        self::command('rm -rf ' . escapeshellarg(self::$temporaryDirectory), dirname(self::$temporaryDirectory));
    }

    protected function tearDown(): void
    {
        $this->stopFixtures();
    }

    private function stopFixtures(): void
    {
        foreach ($this->processes as $process) {
            @proc_terminate($process);
            @proc_close($process);
        }
        $this->processes = [];
    }

    public function testTrustedTlsCertificateAllowsAuthenticationAndDelivery(): void
    {
        $record = $this->recordFor('trusted');
        $port = $this->startFixture('trusted', $record);

        $sent = $this->runMailer($port);
        $this->stopFixtures();
        $commands = $this->commands($record);
        self::assertTrue($sent, 'A CA-trusted localhost certificate must permit authenticated delivery: ' . $this->mailerOutput . '; ' . implode(',', $commands));
        self::assertContains('STARTTLS', $commands);
        self::assertContains('marker-user|marker-password-credential-marker-7d3d', $commands);
    }

    /** @dataProvider invalidCertificateProvider */
    public function testInvalidCertificateNeverReachesAuthentication(string $certificate): void
    {
        $record = $this->recordFor($certificate);
        $port = $this->startFixture($certificate, $record);

        self::assertFalse($this->runMailer($port), $certificate . ' certificate must be rejected.');
        $this->stopFixtures();
        $transcript = implode("\n", $this->commands($record));
        self::assertStringNotContainsString('AUTH', $transcript);
        self::assertStringNotContainsString('marker-user', $transcript);
        self::assertStringNotContainsString('marker-password', $transcript);
        self::assertStringNotContainsString('credential-marker-7d3d', $transcript);
    }

    /** @return array<string, array{string}> */
    public static function invalidCertificateProvider(): array
    {
        return [
            'self signed' => ['self-signed'],
            'expired' => ['expired'],
            'wrong host' => ['wrong-host'],
            'untrusted issuer' => ['untrusted'],
        ];
    }

    public function testAuthenticatedPlaintextIsRejectedBeforeConnection(): void
    {
        require_once __DIR__ . '/../../src/functions.php';
        self::assertFalse(\validateSmtpSecurityPolicy(true, ''));
    }

    public function testUnauthenticatedPlaintextIsAllowed(): void
    {
        require_once __DIR__ . '/../../src/functions.php';
        self::assertTrue(\validateSmtpSecurityPolicy(false, ''));
    }

    public function testAuthenticatedTlsAndSslAreAccepted(): void
    {
        require_once __DIR__ . '/../../src/functions.php';
        self::assertTrue(\validateSmtpSecurityPolicy(true, 'tls'));
        self::assertTrue(\validateSmtpSecurityPolicy(true, 'ssl'));
    }

    public function testTransportHelperConfiguresStrictVerificationAndTimeouts(): void
    {
        require_once __DIR__ . '/../../src/functions.php';

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        \configureSmtpTransportSecurity($mail, 2);
        $smtp = $mail->getSMTPInstance();

        self::assertSame(2, $mail->Timeout);
        self::assertSame(2, $smtp->Timeout);
        self::assertSame(2, $smtp->Timelimit);
        self::assertSame([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ], $mail->SMTPOptions);
    }

    private static function certificate(string $name, string $commonName, string $caCertificate, string $caKey, int $days = 2): void
    {
        $directory = self::$temporaryDirectory;
        self::command(sprintf('openssl req -newkey rsa:2048 -nodes -keyout %1$s.key -out %1$s.csr -subj /CN=%2$s', escapeshellarg($name), escapeshellarg($commonName)), $directory);
        $subjectAlternativeName = 'subjectAltName=DNS:' . $commonName;
        if ($commonName === 'localhost') {
            $subjectAlternativeName .= ',IP:127.0.0.1';
        }
        file_put_contents($directory . '/' . $name . '.ext', $subjectAlternativeName . "\n");
        self::command(sprintf('openssl x509 -req -in %1$s.csr -CA %2$s -CAkey %3$s -CAcreateserial -out %1$s.pem -days %4$d -sha256 -extfile %1$s.ext', escapeshellarg($name), escapeshellarg($caCertificate), escapeshellarg($caKey), $days), $directory);
    }

    private function startFixture(string $certificate, string $record): int
    {
        $port = random_int(20000, 45000);
        $script = __DIR__ . '/fixtures/smtp_fixture.py';
        $command = sprintf('exec python3 %s --port %d --certificate %s --key %s --record %s > %s 2>&1', escapeshellarg($script), $port, escapeshellarg(self::$temporaryDirectory . '/' . $certificate . '.pem'), escapeshellarg(self::$temporaryDirectory . '/' . $certificate . '.key'), escapeshellarg($record), escapeshellarg($record . '.err'));
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::$temporaryDirectory);
        self::assertIsResource($process);
        $this->processes[] = $process;
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $port);
            if (is_resource($socket)) {
                fclose($socket);
                return $port;
            }
            usleep(100000);
        }
        self::fail('SMTP fixture did not start.');
    }

    private function runMailer(int $port): bool
    {
        $script = <<<'PHP'
<?php
require $argv[1] . '/vendor/autoload.php';
require $argv[1] . '/src/functions.php';
$mail = new PHPMailer\PHPMailer\PHPMailer(true);
$mail->isSMTP(); $mail->Host = 'localhost'; $mail->Port = (int) $argv[2];
$mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
$mail->SMTPAuth = true; $mail->Username = 'marker-user'; $mail->Password = 'marker-password-credential-marker-7d3d';
configureSmtpTransportSecurity($mail, 2);
$mail->setFrom('from@example.test'); $mail->addAddress('to@example.test'); $mail->Subject = 'fixture'; $mail->Body = 'fixture';
try { $mail->send(); exit(0); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(1); }
PHP;
        $file = self::$temporaryDirectory . '/mailer.php';
        file_put_contents($file, $script);
        $command = sprintf('php -d openssl.cafile=%s %s %s %d %s', escapeshellarg(self::$temporaryDirectory . '/ca.pem'), escapeshellarg($file), escapeshellarg(dirname(__DIR__, 2)), $port, escapeshellarg(self::$temporaryDirectory . '/ca.pem'));
        exec($command . ' 2>&1', $unused, $status);
        $this->mailerOutput = implode("\n", $unused);
        return $status === 0;
    }

    /** @return list<string> */
    private function commands(string $record): array
    {
        for ($attempt = 0; $attempt < 20 && !is_file($record); $attempt++) {
            usleep(50000);
        }
        $contents = is_file($record) ? file_get_contents($record) : false;
        self::assertIsString($contents, 'SMTP fixture did not write a transcript: ' . (string) @file_get_contents($record . '.err'));
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded['commands'] ?? [];
    }

    private function recordFor(string $certificate): string
    {
        return self::$temporaryDirectory . '/' . $certificate . '-' . bin2hex(random_bytes(4)) . '.json';
    }

    private static function command(string $command, string $directory): void
    {
        exec('cd ' . escapeshellarg($directory) . ' && ' . $command . ' 2>&1', $output, $status);
        if ($status !== 0) {
            throw new \RuntimeException(implode("\n", $output));
        }
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class PhpRuntimePolicyTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testPhp85FpmRuntimeUsesTheDedicatedSocketConfiguration(): void
    {
        $fpmConfig = $this->readRepositoryFile('docker/php/php-fpm.conf');
        $poolConfig = $this->readRepositoryFile('docker/php/www.conf');
        $supervisorConfig = $this->readRepositoryFile('docker/scripts/supervisord.conf');
        $nginxConfig = $this->readRepositoryFile('docker/nginx/default.conf');

        $this->assertStringContainsString('error_log = /proc/self/fd/2', $fpmConfig);
        $this->assertStringContainsString('include = /etc/php/8.5/fpm/pool.d/*.conf', $fpmConfig);
        $this->assertStringContainsString('listen = /run/php/php8.5-fpm.sock', $poolConfig);
        $this->assertStringContainsString('user = www-data', $poolConfig);
        $this->assertStringContainsString('group = www-data', $poolConfig);
        $this->assertStringContainsString('listen.owner = www-data', $poolConfig);
        $this->assertStringContainsString('listen.group = www-data', $poolConfig);
        $this->assertStringContainsString('clear_env = no', $poolConfig);
        $this->assertStringContainsString('catch_workers_output = yes', $poolConfig);
        $this->assertStringContainsString('php_admin_flag[session.upload_progress.enabled] = off', $poolConfig);
        $this->assertStringContainsString('php_admin_value[session.save_path] = /var/lib/php/sessions', $poolConfig);
        $this->assertStringContainsString('php_admin_value[session.gc_probability] = 1', $poolConfig);
        $this->assertStringContainsString('php_admin_value[session.gc_divisor] = 1000', $poolConfig);
        $this->assertStringContainsString('pcntl_fork', $poolConfig);
        $this->assertStringContainsString(
            'command=/usr/local/sbin/php-fpm -F -y /etc/php/8.5/fpm/php-fpm.conf',
            $supervisorConfig
        );
        $this->assertSame(3, substr_count($nginxConfig, 'fastcgi_pass unix:/run/php/php8.5-fpm.sock;'));
    }

    public function testEntrypointWritesOverridesToThePhp85SharedScanDirectory(): void
    {
        $entrypoint = $this->readRepositoryFile('docker/scripts/entrypoint.sh');

        $this->assertStringContainsString('/etc/php/8.5/fpm/conf.d/custom.ini', $entrypoint);
        $this->assertStringNotContainsString('/etc/php/8.1/', $entrypoint);
    }

    public function testRuntimeCheckerUsesValidFpmPolicyAssertionAndSessionGcProbe(): void
    {
        $checker = $this->readRepositoryFile('tests/Security/Runtime/php-runtime-security.sh');

        $this->assertStringNotContainsString("php -r '\\\n", $checker);
        $this->assertStringContainsString("session_gc();", $checker);
        $this->assertStringContainsString("session_gc_fixture_deleted", $checker);
        $this->assertStringContainsString("stat -c '%U:%G:%a' /var/lib/php/sessions", $checker);
    }

    public function testSourceBuildIsPinnedToTheVerifiedPhpReleaseOnUbuntuMariaDb10_6(): void
    {
        $buildScript = $this->readRepositoryFile('docker/scripts/build-php.sh');

        $this->assertStringContainsString('readonly PHP_VERSION="8.5.12"', $buildScript);
        $this->assertStringContainsString(
            'readonly PHP_SHA256="08bc2eba2a588654ade1357174aa646b761556c862dda0678345ee29ed481209"',
            $buildScript
        );
        $this->assertStringContainsString('sha256sum --check --status', $buildScript);
        $this->assertStringContainsString('--with-mysql-sock=/run/mysqld/mysqld.sock', $buildScript);
        $this->assertStringContainsString('--with-config-file-scan-dir=/etc/php/8.5/fpm/conf.d', $buildScript);
        $this->assertStringContainsString('--with-libedit', $buildScript);
        $this->assertStringNotContainsString('--with-readline', $buildScript);

        foreach (['docker/Dockerfile', 'docker/Dockerfile.dev'] as $dockerfile) {
            $contents = $this->readRepositoryFile($dockerfile);
            $this->assertStringContainsString('FROM ubuntu:22.04', $contents);
            $this->assertStringContainsString('mariadb-server', $contents);
            $this->assertStringContainsString('COPY --from=php-builder /opt/php /opt/php', $contents);
            $this->assertStringContainsString(
                'install -d -m 0700 -o www-data -g www-data /var/lib/php/sessions',
                $contents
            );
            $this->assertStringContainsString('libedit-dev', $contents);
            $this->assertStringContainsString('libedit2', $contents);
            $this->assertStringNotContainsString('libreadline-dev', $contents);
            $this->assertStringNotContainsString('libreadline8', $contents);
        }

        $dockerIgnore = $this->readRepositoryFile('.dockerignore');
        $this->assertStringContainsString('**/._*', $dockerIgnore);
    }

    private function readRepositoryFile(string $relativePath): string
    {
        $path = self::ROOT . '/' . $relativePath;
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        return $contents;
    }
}

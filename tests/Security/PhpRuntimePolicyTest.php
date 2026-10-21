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
        $customIni = $this->readRepositoryFile('docker/php/custom.ini');
        $supervisorConfig = $this->readRepositoryFile('docker/scripts/supervisord.conf');
        $nginxConfig = $this->readRepositoryFile('docker/nginx/default.conf');

        $this->assertStringContainsString('error_log = /proc/self/fd/2', $fpmConfig);
        $this->assertStringContainsString('include = /etc/php/8.5/fpm/pool.d/*.conf', $fpmConfig);
        $this->assertStringContainsString('listen = /run/php/php8.5-fpm.sock', $poolConfig);
        $this->assertStringContainsString('user = www-data', $poolConfig);
        $this->assertStringContainsString('group = www-data', $poolConfig);
        $this->assertStringContainsString('listen.owner = www-data', $poolConfig);
        $this->assertStringContainsString('listen.group = www-data', $poolConfig);
        $this->assertStringContainsString('clear_env = yes', $poolConfig);
        $this->assertStringContainsString('env[PATH] = /usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', $poolConfig);
        $this->assertStringContainsString('env[MYSQL_PASSWORD] = $MYSQL_PASSWORD', $poolConfig);
        $this->assertStringContainsString('catch_workers_output = yes', $poolConfig);
        $this->assertStringContainsString('php_admin_flag[session.upload_progress.enabled] = off', $poolConfig);
        $this->assertStringContainsString('php_admin_value[session.save_path] = /var/lib/php/sessions', $poolConfig);
        $this->assertStringContainsString('php_admin_value[session.gc_probability] = 1', $poolConfig);
        $this->assertStringContainsString('php_admin_value[session.gc_divisor] = 100', $poolConfig);
        $this->assertStringContainsString('php_admin_value[session.gc_maxlifetime] = 1440', $poolConfig);
        $this->assertStringContainsString('memory_limit = 512M', $customIni);
        $this->assertStringContainsString('pcntl_fork', $poolConfig);
        $this->assertStringContainsString(
            'command=/usr/local/sbin/php-fpm -F -y /etc/php/8.5/fpm/php-fpm.conf',
            $supervisorConfig
        );
        $this->assertSame(8, substr_count($nginxConfig, 'fastcgi_pass unix:/run/php/php8.5-fpm.sock;'));
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

    public function testComposerCopySourcesUseThePatchedVerifiedRelease(): void
    {
        // Official composer:2.10.3 OCI index (AMD64 and ARM64); patches the Composer 2.8.12 security issues.
        $expectedRef = 'composer:2.10.3@sha256:d0c32820c62cc860fddd22adceed4eeda7698b093a7a88d503414172ce400626';

        foreach (['docker/Dockerfile', 'docker/Dockerfile.dev'] as $dockerfile) {
            $contents = $this->readRepositoryFile($dockerfile);

            preg_match_all(
                '/^\s*COPY --from=(\S+) \/usr\/bin\/composer \/usr\/bin\/composer\s*$/m',
                $contents,
                $matches
            );
            $this->assertCount(1, $matches[1], sprintf('%s must copy Composer from exactly one image.', $dockerfile));

            $ref = $matches[1][0];
            $this->assertMatchesRegularExpression(
                '/^composer:(\d+\.\d+\.\d+)@sha256:[0-9a-f]{64}$/',
                $ref,
                sprintf('%s must pin Composer by tag and sha256 digest.', $dockerfile)
            );
            preg_match('/^composer:(\d+\.\d+\.\d+)@/', $ref, $version);
            $this->assertTrue(
                version_compare($version[1], '2.10.3', '>='),
                sprintf('%s must use Composer 2.10.3 or newer.', $dockerfile)
            );
            $this->assertSame($expectedRef, $ref, sprintf('%s must use the verified Composer ref.', $dockerfile));
        }
    }

    public function testProductionNpmCiAndRelativeTerserRunInsideTheApplicationDirectory(): void
    {
        $dockerfile = $this->readRepositoryFile('docker/Dockerfile');
        $lines = explode("\n", $dockerfile);

        $manifestCopyLine = $this->findLineIndex($lines, 'COPY package.json package-lock.json /var/www/html/');
        $npmCiLine = $this->findLineIndex($lines, 'RUN npm ci --ignore-scripts');
        $this->assertLessThan($npmCiLine, $manifestCopyLine, 'Manifest must be copied before npm ci.');

        $this->assertSame('/var/www/html', $this->effectiveWorkdirAtLine($lines, $npmCiLine));

        $terserLine = $this->findLineIndex($lines, './node_modules/.bin/terser');
        $this->assertGreaterThan($npmCiLine, $terserLine);
        $this->assertSame('/var/www/html', $this->effectiveWorkdirAtLine($lines, $terserLine));
    }

    /** @param list<string> $lines */
    private function findLineIndex(array $lines, string $needle): int
    {
        foreach ($lines as $index => $line) {
            if (str_contains($line, $needle)) {
                return $index;
            }
        }

        $this->fail(sprintf('Dockerfile line not found: %s', $needle));
    }

    /** @param list<string> $lines */
    private function effectiveWorkdirAtLine(array $lines, int $targetIndex): string
    {
        $workdir = '/';

        for ($index = 0; $index < $targetIndex; $index++) {
            if (preg_match('/^\s*WORKDIR\s+(\S+)\s*$/', $lines[$index], $matches) === 1) {
                $workdir = $matches[1];
            }
        }

        return $workdir;
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

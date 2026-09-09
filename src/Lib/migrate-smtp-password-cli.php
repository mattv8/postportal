<?php

declare(strict_types=1);

/**
 * CLI entrypoint for SMTP password v1→v2 migration.
 * Safely includable by both CLI and PHPUnit.
 *
 * Direct execution: php migrate-smtp-password-cli.php
 * Inclusion: require_once 'migrate-smtp-password-cli.php' (no code executes)
 */

use PostPortal\Lib\MigrateSmtpCiphertext;
use PostPortal\Lib\SmtpPasswordPersistenceMysqli;

/**
 * Run SMTP password v1→v2 migration CLI logic.
 * Validates configuration, connects to database, and executes migration.
 * Always closes any opened connection via try/finally.
 *
 * @return int Exit code: 1 for configuration error, 2 for runtime/database error, 0/2/3 for migration result
 */
function runSmtpPasswordMigrationCli(): int
{
    $conn = null;
    try {
        // Find Composer autoload in deployed or repo-copy layout
         $autoloadCandidates = [
             __DIR__ . '/../vendor/autoload.php',      // Deployed: /var/www/html/Lib/../vendor -> /var/www/html/vendor
             __DIR__ . '/../../vendor/autoload.php',   // Repo-copy: src/Lib/../../vendor
         ];

        $autoloadLoaded = false;
        foreach ($autoloadCandidates as $candidate) {
            if (file_exists($candidate)) {
                require_once $candidate;
                $autoloadLoaded = true;
                break;
            }
        }

        if (!$autoloadLoaded) {
            fwrite(STDERR, "Database error\n");
            return 2;
        }

        // Validate APP_ENCRYPTION_KEY: must be exactly 64 hexadecimal characters
        $appKey = getenv('APP_ENCRYPTION_KEY');
        if ($appKey === false || $appKey === '') {
            fwrite(STDERR, "Configuration error\n");
            return 1;
        }

        if (!ctype_xdigit($appKey) || strlen($appKey) !== 64) {
            fwrite(STDERR, "Configuration error\n");
            return 1;
        }

        // Validate required database environment variables
        $host = getenv('MYSQL_HOST');
        $user = getenv('MYSQL_USER');
        $password = getenv('MYSQL_PASSWORD');
        $database = getenv('MYSQL_DATABASE');

        if ($host === false || $host === '' ||
            $user === false || $user === '' ||
            $password === false || $password === '' ||
            $database === false || $database === '') {
            fwrite(STDERR, "Configuration error\n");
            return 1;
        }

        // Connect to database
        $conn = new \mysqli($host, $user, $password, $database);
        if ($conn->connect_errno) {
            fwrite(STDERR, "Database error\n");
            return 2;
        }

        // Create persistence adapter and migration
        $persistence = new SmtpPasswordPersistenceMysqli($conn);
        $migration = new MigrateSmtpCiphertext($persistence);

        // Run migration
        $exitCode = $migration->run();

        return $exitCode;
    } catch (Throwable $e) {
        fwrite(STDERR, "Database error\n");
        return 2;
    } finally {
        if ($conn instanceof \mysqli) {
            $conn->close();
        }
    }
}

// Only execute if this file is run directly (not included/required)
// Check: canonical script path comparison
$isDirectExecution = false;
if (isset($GLOBALS['argv'][0]) || isset($_SERVER['argv'][0])) {
    $scriptPath = $GLOBALS['argv'][0] ?? $_SERVER['argv'][0] ?? null;
    if ($scriptPath) {
        $scriptReal = @realpath($scriptPath);
        $thisReal = @realpath(__FILE__);
        $isDirectExecution = ($scriptReal === $thisReal);
    }
}

if ($isDirectExecution) {
    exit(runSmtpPasswordMigrationCli());
}

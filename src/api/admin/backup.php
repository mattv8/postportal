<?php

declare(strict_types=1);

use PostPortal\Http\ApiHandler;
use PostPortal\Http\ArchiveValidator;
use PostPortal\Http\ErrorResponse;

require_once __DIR__ . '/../bootstrap.php';

ApiHandler::handle(function (): void {
    // Require admin authentication
    bootstrapApi(requireAuth: true, requireAdmin: true);
    requireCsrfToken();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ErrorResponse::json(405, 'Method not allowed');
    }

    $uploadsDir = realpath(__DIR__ . '/../../storage/uploads');

    if (!$uploadsDir || !is_dir($uploadsDir)) {
        ErrorResponse::internalError('Uploads directory not found');
    }

    // Create temp directory for backup
    $tempDir = sys_get_temp_dir() . '/postportal_backup_' . bin2hex(random_bytes(16));
    if (!mkdir($tempDir, 0700, true) || @chmod($tempDir, 0700) === false) {
        ErrorResponse::internalError('Failed to create temporary directory');
    }

    try {
        // Step 1: Dump database using the 'export' wrapper command
        // Use full path to avoid shell built-in 'export' command
        $dumpFile = $tempDir . '/database.sql';
        $exportCmd = sprintf('/usr/local/bin/export > %s 2>/dev/null', escapeshellarg($dumpFile));

        $output = [];
        $returnCode = 0;
        exec($exportCmd, $output, $returnCode);

        if ($returnCode !== 0 || !is_file($dumpFile) || filesize($dumpFile) === 0) {
            throw new RuntimeException('Database dump failed');
        }

        // Step 2: Copy uploads directory
        $uploadsBackupDir = $tempDir . '/uploads';
        $uploadsCopy = ArchiveValidator::copyUploadsForBackup($uploadsDir, $uploadsBackupDir);
        if (!$uploadsCopy['success']) {
            throw new RuntimeException('Uploads backup failed');
        }

        // Step 3: Add metadata
        $metadata = [
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'version' => '1.0',
            'php_version' => PHP_VERSION,
        ];
        if (file_put_contents($tempDir . '/backup-meta.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('Metadata write failed');
        }

        // Step 4: Create tar.gz archive
        $timestamp = date('Y-m-d_His');
        $archiveName = "postportal_backup_{$timestamp}.tar.gz";
        $archivePath = sys_get_temp_dir() . '/' . $archiveName;

        $tarCmd = sprintf(
            'cd %s && tar -czf %s . 2>&1',
            escapeshellarg($tempDir),
            escapeshellarg($archivePath)
        );
        exec($tarCmd, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($archivePath)) {
            throw new RuntimeException('Failed to create archive: ' . implode("\n", $output));
        }

        // Cleanup temp directory
        ArchiveValidator::cleanupStaging($tempDir);

        // Step 5: Stream the archive to the user
        $fileSize = filesize($archivePath);

        // Clear any buffered output
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Send headers for file download
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $archiveName . '"');
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');

        // Stream the file
        $handle = fopen($archivePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Failed to open archive for streaming');
        }

        while (!feof($handle)) {
            echo fread($handle, 8192);
            flush();
        }
        fclose($handle);

        // Cleanup archive
        @unlink($archivePath);
        exit;
    } catch (Throwable $e) {
        // Cleanup on error
        ArchiveValidator::cleanupStaging($tempDir);
        if (isset($archivePath) && file_exists($archivePath)) {
            @unlink($archivePath);
        }

        error_log('Backup operation failed');
        ErrorResponse::internalError('Backup operation failed');
    }
});

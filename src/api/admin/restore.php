<?php

declare(strict_types=1);

use PostPortal\Http\ApiHandler;
use PostPortal\Http\ArchiveValidator;
use PostPortal\Http\ErrorResponse;

require_once __DIR__ . '/../bootstrap.php';

ApiHandler::handle(function (): void {
    bootstrapApi(requireAuth: true, requireAdmin: true);
    requireCsrfToken();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') ErrorResponse::json(405, 'Method not allowed');
    if (!isset($_FILES['backup']) || $_FILES['backup']['error'] !== UPLOAD_ERR_OK) ErrorResponse::badRequest('Invalid backup archive');

    $uploadedFile = $_FILES['backup']['tmp_name'];
    $uploadsDir = realpath(__DIR__ . '/../../storage/uploads');
    if ($uploadsDir === false || !is_dir($uploadsDir)) ErrorResponse::internalError('Backup restoration failed');

    $stagingDir = null;
    $preparedUploads = null;
    $oldUploads = null;
    $snapshot = null;
    $databaseChanged = false;
    $uploadsActivated = false;
    $rollbackVerified = false;
    $response = null;
    $invalidArchive = false;

    try {
        // Preflight and staging are non-destructive: invalid input is a client error.
        if (!ArchiveValidator::validateArchiveStructure($uploadedFile)['valid']) throw new RuntimeException('invalid archive');
        $extracted = ArchiveValidator::extractToStaging($uploadedFile);
        if (!$extracted['success']) throw new RuntimeException('invalid archive');
        $stagingDir = $extracted['staging_dir'];
        if (!ArchiveValidator::validateDatabaseDump($stagingDir)['valid'] || !ArchiveValidator::validateMediaFiles($stagingDir)['valid']) {
            throw new RuntimeException('invalid archive');
        }
        $metadataPath = $stagingDir . '/backup-meta.json';
        $metadata = null;
        if (file_exists($metadataPath)) {
            $stat = @lstat($metadataPath);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] > 1048576) throw new RuntimeException('invalid archive');
            try {
                $metadata = json_decode((string) file_get_contents($metadataPath), true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new RuntimeException('invalid archive');
            }
            if (!is_array($metadata) || array_is_list($metadata)) throw new RuntimeException('invalid archive');
        }

        // Prepare the complete replacement before the database is touched.
        $prepared = ArchiveValidator::prepareUploadsReplacement($stagingDir, $uploadsDir);
        if (!$prepared['success']) throw new RuntimeException('prepare uploads failed');
        $preparedUploads = $prepared['prepared_path'];

        $snapshotResult = ArchiveValidator::createDatabaseSnapshot();
        if (!$snapshotResult['success']) throw new RuntimeException('snapshot failed');
        $snapshot = $snapshotResult['snapshot_file'];

        // Every database transition below is recoverable from the retained snapshot.
        $databaseChanged = true;
        if (restoreCommand(['/usr/local/bin/import', $stagingDir . '/database.sql']) !== 0) throw new RuntimeException('import failed');
        if (restoreCommand(['/usr/local/bin/migrate']) !== 0) throw new RuntimeException('migrations failed');
        if (restoreCommand(['php', '/var/www/html/Lib/migrate-smtp-password-cli.php']) !== 0) throw new RuntimeException('smtp migration failed');

        // Upload activation is last and consists only of same-filesystem renames.
        $activation = ArchiveValidator::activateUploadsReplacement($uploadsDir, $preparedUploads);
        $oldUploads = $activation['old_path'] ?? null;
        if (!$activation['success']) throw new RuntimeException('uploads activation failed');
        $uploadsActivated = true;
        $preparedUploads = null;

        $response = ['message' => 'Backup restored successfully', 'restored_at' => date('c')];
        if ($metadata !== null) {
            $response['backup_created_at'] = $metadata['created_at'] ?? null;
            $response['backup_version'] = $metadata['version'] ?? null;
        }
        if ($oldUploads !== null && ArchiveValidator::finalizeUploadsReplacement($oldUploads)['success']) {
            $oldUploads = null;
        } elseif ($oldUploads !== null) {
            error_log('Restore cleanup requires operator intervention');
        }
    } catch (Throwable $exception) {
        $invalidArchive = $exception->getMessage() === 'invalid archive';
        error_log('Restore workflow failed');

        // Roll back each state at most once; retain artifacts if either recovery step fails.
        $uploadsRolledBack = !$uploadsActivated && $oldUploads === null;
        if ($oldUploads !== null) {
            $uploadsRolledBack = ArchiveValidator::rollbackUploadsReplacement($uploadsDir, $oldUploads)['success'];
            if ($uploadsRolledBack) $oldUploads = null;
        }
        $databaseRolledBack = !$databaseChanged;
        if ($databaseChanged && $snapshot !== null) {
            $databaseRolledBack = ArchiveValidator::restoreDatabaseSnapshot($snapshot)['success'];
        }
        $rollbackVerified = $uploadsRolledBack && $databaseRolledBack;
        if (!$rollbackVerified) error_log('Restore recovery retained artifacts for operator intervention');
    } finally {
        if ($stagingDir !== null) ArchiveValidator::cleanupStaging($stagingDir);
        if ($response !== null || $rollbackVerified || (!$databaseChanged && $snapshot === null)) {
            if ($preparedUploads !== null) ArchiveValidator::cleanupStaging($preparedUploads);
            if ($snapshot !== null) ArchiveValidator::cleanupSnapshot($snapshot);
        }
    }

    if ($response !== null) {
        // Cache failures cannot invalidate an otherwise committed restore.
        restoreCommand(['cache-purge']);
        ErrorResponse::success($response);
    }
    if ($invalidArchive) ErrorResponse::badRequest('Invalid backup archive');
    ErrorResponse::internalError('Backup restoration failed');
});

/** @param list<string> $command */
function restoreCommand(array $command): int
{
    $process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    return is_resource($process) ? proc_close($process) : 1;
}

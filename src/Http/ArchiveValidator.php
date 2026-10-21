<?php

declare(strict_types=1);

namespace PostPortal\Http;

use PostPortal\Lib\MediaPolicy;
use RuntimeException;
use Throwable;

/** Validates and extracts hostile restore archives without invoking tar. */
final class ArchiveValidator
{
    private const MAX_COMPRESSED_SIZE = 500 * 1024 * 1024;
    private const MAX_EXPANDED_SIZE = 2 * 1024 * 1024 * 1024;
    private const MAX_FILE_COUNT = 10000;
    private const MAX_PER_FILE_SIZE = 200 * 1024 * 1024;
    private const MAX_METADATA_SIZE = 1 * 1024 * 1024;
    private const ALLOWED_IMAGE_MIMES = [
        'image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'],
        'image/webp' => ['webp'], 'image/gif' => ['gif'],
    ];

    /** @return array{valid:bool,error?:string} */
    public static function validateArchiveStructure(string $archivePath): array
    {
        try {
            self::assertArchiveSize($archivePath);
            self::processArchive($archivePath, null);
            return ['valid' => true];
        } catch (Throwable) {
            return ['valid' => false, 'error' => 'Archive validation failed'];
        }
    }

    /** @return array{success:bool,staging_dir?:string,error?:string} */
    public static function extractToStaging(string $archivePath): array
    {
        $stagingDir = sys_get_temp_dir() . '/postportal_restore_' . bin2hex(random_bytes(16));
        try {
            self::assertArchiveSize($archivePath);
            if (!mkdir($stagingDir, 0700, true) || @chmod($stagingDir, 0700) === false) {
                throw new RuntimeException('Cannot create staging directory');
            }
            self::processArchive($archivePath, $stagingDir);
            return ['success' => true, 'staging_dir' => $stagingDir];
        } catch (Throwable) {
            self::recursiveRmDir($stagingDir);
            return ['success' => false, 'error' => 'Archive extraction validation failed'];
        }
    }

    /** @return array{valid:bool,error?:string} */
    public static function validateDatabaseDump(string $stagingDir): array
    {
        $dump = $stagingDir . '/database.sql';
        $stat = @lstat($dump);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] < 1 || $stat['size'] > self::MAX_PER_FILE_SIZE || !self::isContained($stagingDir, $dump)) {
            return ['valid' => false, 'error' => 'Database dump validation failed'];
        }
        $handle = @fopen($dump, 'rb');
        if ($handle === false) {
            return ['valid' => false, 'error' => 'Database dump validation failed'];
        }
        try {
            while (!feof($handle)) {
                if (fread($handle, 8192) === false) {
                    throw new RuntimeException('Read failure');
                }
            }
            fclose($handle);
            return ['valid' => true];
        } catch (Throwable) {
            fclose($handle);
            return ['valid' => false, 'error' => 'Database dump validation failed'];
        }
    }

    /** @return array{valid:bool,error?:string} */
    public static function validateMediaFiles(string $stagingDir): array
    {
        $uploads = $stagingDir . '/uploads';
        if (!file_exists($uploads)) {
            return ['valid' => true];
        }
        if (!self::isDirectory($uploads) || !self::isContained($stagingDir, $uploads)) {
            return ['valid' => false, 'error' => 'Media validation failed'];
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return ['valid' => false, 'error' => 'Media validation failed'];
        }
        try {
            self::walkMedia($uploads, $stagingDir, $finfo);
            return ['valid' => true];
        } catch (Throwable) {
            return ['valid' => false, 'error' => 'Media validation failed'];
        }
    }

    /**
     * Builds a complete replacement uploads tree beside the live tree, so activation
     * is a same-filesystem rename rather than a partial merge.
     *
     * @return array{success:bool,prepared_path?:string,error?:string}
     */
    public static function prepareUploadsReplacement(string $stagingDir, string $liveUploadsDir): array
    {
        $parent = dirname($liveUploadsDir);
        $prepared = $parent . '/.restore_new_' . bin2hex(random_bytes(12));
        try {
            if (!self::isDirectory($parent) || !self::isDirectory($liveUploadsDir) || !self::isContained($parent, $liveUploadsDir)) {
                throw new RuntimeException('Unsafe uploads directory');
            }
            if (!mkdir($prepared, 0700) || @chmod($prepared, 0700) === false || !self::isDirectory($prepared)) {
                throw new RuntimeException('Cannot create replacement directory');
            }
            self::copyValidatedUploadsTree($stagingDir . '/uploads', $prepared);
            return ['success' => true, 'prepared_path' => $prepared];
        } catch (Throwable) {
            self::recursiveRmDir($prepared);
            return ['success' => false, 'error' => 'Failed to prepare replacement uploads'];
        }
    }

    /** @return array{success:bool,old_path?:string,error?:string} */
    public static function activateUploadsReplacement(string $liveUploadsDir, string $preparedPath): array
    {
        $old = dirname($liveUploadsDir) . '/.restore_old_' . bin2hex(random_bytes(12));
        try {
            if (!self::isDirectory($liveUploadsDir) || !self::isDirectory($preparedPath) || dirname($liveUploadsDir) !== dirname($preparedPath)) {
                throw new RuntimeException('Unsafe replacement paths');
            }
            if (!@rename($liveUploadsDir, $old)) {
                throw new RuntimeException('Cannot retain existing uploads');
            }
            if (!@rename($preparedPath, $liveUploadsDir)) {
                if (!@rename($old, $liveUploadsDir)) {
                    return ['success' => false, 'old_path' => $old, 'error' => 'Upload activation rollback failed'];
                }
                return ['success' => false, 'error' => 'Upload activation failed'];
            }
            return ['success' => true, 'old_path' => $old];
        } catch (Throwable) {
            return ['success' => false, 'error' => 'Upload activation failed'];
        }
    }

    /** @return array{success:bool,error?:string} */
    public static function rollbackUploadsReplacement(string $liveUploadsDir, string $oldPath): array
    {
        try {
            if (!self::isDirectory($oldPath) || dirname($liveUploadsDir) !== dirname($oldPath)) {
                throw new RuntimeException('Unsafe rollback paths');
            }
            if (file_exists($liveUploadsDir) || is_link($liveUploadsDir)) {
                self::removeTreeStrict($liveUploadsDir);
            }
            if (!@rename($oldPath, $liveUploadsDir)) {
                throw new RuntimeException('Cannot restore previous uploads');
            }
            return ['success' => true];
        } catch (Throwable) {
            return ['success' => false, 'error' => 'Upload rollback failed'];
        }
    }

    /** @return array{success:bool,error?:string} */
    public static function finalizeUploadsReplacement(string $oldPath): array
    {
        try {
            if (file_exists($oldPath) || is_link($oldPath)) {
                self::removeTreeStrict($oldPath);
            }
            return ['success' => true];
        } catch (Throwable) {
            return ['success' => false, 'error' => 'Failed to finalize upload replacement'];
        }
    }

    /** @return array{success:bool,error?:string} */
    public static function copyUploadsForBackup(string $uploadsDir, string $destination): array
    {
        try {
            if (!self::isDirectory($uploadsDir) || !mkdir($destination, 0700, true) || !self::isDirectory($destination)) {
                throw new RuntimeException('Cannot prepare backup uploads');
            }
            self::copyValidatedUploadsTree($uploadsDir, $destination);
            return ['success' => true];
        } catch (Throwable) {
            self::recursiveRmDir($destination);
            return ['success' => false, 'error' => 'Failed to copy backup uploads'];
        }
    }

    public static function cleanupStaging(string $stagingDir): void { self::recursiveRmDir($stagingDir); }

    /** @return array{success:bool,snapshot_id?:string,snapshot_file?:string,error?:string} */
    public static function createDatabaseSnapshot(): array
    {
        $id = 'snap_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $dir = sys_get_temp_dir() . '/postportal_snapshots';
        $file = $dir . '/' . $id . '.sql';
        if ((!is_dir($dir) && !mkdir($dir, 0700, true)) || !is_dir($dir) || @chmod($dir, 0700) === false) return ['success' => false, 'error' => 'Failed to create database snapshot'];
        $host = getenv('MYSQL_HOST') ?: 'localhost'; $user = getenv('MYSQL_USER'); $pass = getenv('MYSQL_PASSWORD'); $database = getenv('MYSQL_DATABASE');
        if (!$user || !$pass || !$database) return ['success' => false, 'error' => 'Database credentials not configured'];
        if (!self::validMysqlIdentifier($database)) return ['success' => false, 'error' => 'Failed to create database snapshot'];
        $oldUmask = umask(0077);
        try {
            $status = self::runCommand(['/usr/bin/mysqldump', '--protocol=TCP', '--single-transaction', '--quick', '-h', $host, '-u', $user, $database], $file, ['MYSQL_PWD' => $pass]);
        } finally {
            umask($oldUmask);
        }
        if ($status !== 0 || !is_file($file) || filesize($file) === 0 || @chmod($file, 0600) === false) { @unlink($file); return ['success' => false, 'error' => 'Failed to create database snapshot']; }
        return ['success' => true, 'snapshot_id' => $id, 'snapshot_file' => $file];
    }

    /** @return array{success:bool,error?:string} */
    public static function restoreDatabaseSnapshot(string $snapshotFile): array
    {
        if (!is_file($snapshotFile)) return ['success' => false, 'error' => 'Snapshot file not found'];
        $status = self::runCommand(['/usr/local/bin/import', $snapshotFile]);
        return $status === 0 ? ['success' => true] : ['success' => false, 'error' => 'Failed to restore database snapshot'];
    }

    public static function cleanupSnapshot(string $snapshotFile): void { if (is_file($snapshotFile) || is_link($snapshotFile)) @unlink($snapshotFile); }

    private static function assertArchiveSize(string $path): void
    {
        $stat = @stat($path);
        if ($stat === false || !is_file($path) || $stat['size'] > self::MAX_COMPRESSED_SIZE) throw new RuntimeException('Invalid archive');
    }

    /** Processes a tar stream once; staging is null for validation-only processing. */
    private static function processArchive(string $path, ?string $staging): void
    {
        $prefix = @file_get_contents($path, false, null, 0, 2);
        $gzip = $prefix === "\x1f\x8b";
        $stream = $gzip ? @gzopen($path, 'rb') : @fopen($path, 'rb');
        if ($stream === false) throw new RuntimeException('Cannot open archive');
        $seen = []; $count = 0; $expanded = 0; $database = 0; $globalPax = []; $nextPax = []; $longName = null; $longLink = null;
        try {
            while (true) {
                $header = self::readExactly($stream, 512, $gzip);
                if ($header === '') throw new RuntimeException('Missing end blocks');
                if ($header === str_repeat("\0", 512)) {
                    if (self::readExactly($stream, 512, $gzip) !== str_repeat("\0", 512)) throw new RuntimeException('Invalid end blocks');
                    break;
                }
                self::assertHeader($header);
                $name = self::field($header, 0, 100);
                $prefixName = self::field($header, 345, 155);
                if ($prefixName !== '') $name = $prefixName . '/' . $name;
                $type = $header[156];
                $size = self::parseTarNumber(substr($header, 124, 12));
                $link = self::field($header, 157, 100);
                if ($size > self::MAX_EXPANDED_SIZE) throw new RuntimeException('Entry too large');
                if (in_array($type, ['L', 'K', 'x', 'g'], true)) {
                    if ($size > self::MAX_METADATA_SIZE || ($expanded += $size) > self::MAX_EXPANDED_SIZE) throw new RuntimeException('Metadata too large');
                    $metadata = self::readEntry($stream, $size, $gzip);
                    if ($type === 'L') $longName = rtrim($metadata, "\0\n");
                    elseif ($type === 'K') $longLink = rtrim($metadata, "\0\n");
                    else {
                        $pax = self::parsePax($metadata);
                        if ($type === 'g') {
                            if (array_intersect_key($pax, array_flip(['path', 'size', 'linkpath'])) !== []) throw new RuntimeException('Unsafe global PAX metadata');
                            $globalPax = array_merge($globalPax, $pax);
                        } else {
                            $nextPax = array_merge($nextPax, $pax);
                        }
                    }
                    continue;
                }
                $pax = array_merge($globalPax, $nextPax); $nextPax = [];
                $name = $pax['path'] ?? $longName ?? $name; $link = $pax['linkpath'] ?? $longLink ?? $link; $longName = $longLink = null;
                if (isset($pax['size'])) $size = self::parsePaxSize($pax['size']);
                if (!in_array($type, ["\0", '0', '5'], true) || $link !== '') throw new RuntimeException('Unsupported entry type');
                $normalized = self::normalizeName($name);
                // Root directory entry is allowed but not counted as content
                if ($normalized === "\x00ROOT\x00") { self::assertAllowedEntry($normalized, $type, $size); self::discardEntry($stream, $size, $gzip); continue; }
                if (isset($seen[$normalized]) || isset($seen[strtolower($normalized)])) throw new RuntimeException('Duplicate archive entry');
                $seen[$normalized] = true; $seen[strtolower($normalized)] = true;
                if (++$count > self::MAX_FILE_COUNT || $size > self::MAX_PER_FILE_SIZE || ($expanded += $size) > self::MAX_EXPANDED_SIZE) throw new RuntimeException('Archive limits exceeded');
                self::assertAllowedEntry($normalized, $type, $size);
                if ($normalized === 'database.sql') ++$database;
                if ($staging === null) self::discardEntry($stream, $size, $gzip);
                else self::extractEntry($stream, $gzip, $staging, $normalized, $type, $size);
            }
            if ($nextPax !== [] || $longName !== null || $longLink !== null || $database !== 1) throw new RuntimeException('Incomplete archive');
        } finally {
            $gzip ? gzclose($stream) : fclose($stream);
        }
    }

    private static function assertHeader(string $header): void
    {
        if (strlen($header) !== 512 || substr($header, 257, 6) !== "ustar\0") throw new RuntimeException('Invalid tar header');
        $stored = self::parseTarNumber(substr($header, 148, 8));
        $unsigned = 0; $signed = 0;
        for ($i = 0; $i < 512; ++$i) { $byte = ($i >= 148 && $i < 156) ? 32 : ord($header[$i]); $unsigned += $byte; $signed += $byte > 127 ? $byte - 256 : $byte; }
        if ($stored !== $unsigned && $stored !== $signed) throw new RuntimeException('Invalid tar checksum');
    }

    private static function parseTarNumber(string $field): int
    {
        if ($field === '' || $field === str_repeat("\0", strlen($field)) || trim($field, " \0") === '') return 0;
        if ((ord($field[0]) & 0x80) !== 0) {
            if ((ord($field[0]) & 0x40) !== 0) throw new RuntimeException('Negative tar size');
            $value = ord($field[0]) & 0x3f;
            for ($i = 1, $length = strlen($field); $i < $length; ++$i) { if ($value > intdiv(PHP_INT_MAX - ord($field[$i]), 256)) throw new RuntimeException('Tar number overflow'); $value = ($value * 256) + ord($field[$i]); }
            return $value;
        }
        if (preg_match('/^[ ]*[0-7]+[ \0]*$/', $field) !== 1) throw new RuntimeException('Invalid tar number');
        $value = 0; foreach (str_split(trim($field, " \0")) as $digit) { if ($value > intdiv(PHP_INT_MAX - (ord($digit) - 48), 8)) throw new RuntimeException('Tar number overflow'); $value = ($value * 8) + ord($digit) - 48; }
        return $value;
    }

    /** @return array<string,string> */
    private static function parsePax(string $payload): array
    {
        $result = []; $offset = 0; $length = strlen($payload);
        while ($offset < $length) {
            $space = strpos($payload, ' ', $offset); if ($space === false || $space === $offset) throw new RuntimeException('Malformed PAX');
            $recordLengthString = substr($payload, $offset, $space - $offset);
            if (preg_match('/^[0-9]+$/', $recordLengthString) !== 1) throw new RuntimeException('Malformed PAX');
            $recordLength = (int) $recordLengthString;
            if ($recordLength <= $space - $offset + 2 || $offset + $recordLength > $length) throw new RuntimeException('Malformed PAX');
            $record = substr($payload, $space + 1, $recordLength - ($space - $offset) - 1);
            if (!str_ends_with($record, "\n")) throw new RuntimeException('Malformed PAX');
            $pair = substr($record, 0, -1); $equals = strpos($pair, '='); if ($equals === false) throw new RuntimeException('Malformed PAX');
            $key = substr($pair, 0, $equals); $value = substr($pair, $equals + 1);
            if (str_contains(strtolower($key), 'sparse')) throw new RuntimeException('Sparse PAX unsupported');
            if (in_array($key, ['path', 'size', 'linkpath'], true)) $result[$key] = $value;
            $offset += $recordLength;
        }
        return $result;
    }

    private static function parsePaxSize(string $value): int
    {
        if (preg_match('/^(0|[1-9][0-9]*)$/', $value) !== 1 || strlen($value) > 18 || (int) $value > PHP_INT_MAX) throw new RuntimeException('Invalid PAX size');
        return (int) $value;
    }

    private static function normalizeName(string $name): string
    {
        if ($name === '' || str_contains($name, "\0") || preg_match('/[\x00-\x1f\x7f]/', $name) === 1 || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) === 1) throw new RuntimeException('Invalid archive path');
        // Special case: root directory entry '.' or './' is allowed only during assertion phase (returns marker)
        if ($name === '.' || $name === './') return "\x00ROOT\x00";
        while (str_starts_with($name, './')) $name = substr($name, 2);
        $name = rtrim($name, '/');
        if ($name === '') throw new RuntimeException('Invalid archive path');
        foreach (explode('/', $name) as $component) if ($component === '' || $component === '.' || $component === '..') throw new RuntimeException('Invalid archive path');
        return $name;
    }

    private static function assertAllowedEntry(string $name, string $type, int $size): void
    {
        // Root directory entry '.' or './' must be directory with size 0, then ignore
        if ($name === "\x00ROOT\x00") { if ($type !== '5' || $size !== 0) throw new RuntimeException('Invalid root directory'); return; }
        if ($name === 'database.sql') { if ($type !== "\0" && $type !== '0') throw new RuntimeException('Invalid database dump'); return; }
        if ($name === 'backup-meta.json') { if ($type !== "\0" && $type !== '0') throw new RuntimeException('Invalid metadata'); return; }
        if ($name === 'uploads') { if ($type !== '5' || $size !== 0) throw new RuntimeException('Invalid uploads root'); return; }
        if (!str_starts_with($name, 'uploads/')) throw new RuntimeException('Unexpected archive entry');
        if ($type === '5') { if ($size !== 0) throw new RuntimeException('Invalid directory'); return; }
        $base = basename($name); $extension = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if ($base[0] === '.' || !in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) throw new RuntimeException('Invalid media entry');
    }

    /** @param resource $stream */
    private static function extractEntry($stream, bool $gzip, string $root, string $name, string $type, int $size): void
    {
        $destination = $root . '/' . $name;
        if (!self::isContained($root, $destination)) throw new RuntimeException('Path escapes staging');
        if ($type === '5') { self::createDirectory($root, $destination); self::readEntry($stream, $size, $gzip); return; }
        self::createDirectory($root, dirname($destination));
        if (file_exists($destination) || is_link($destination)) throw new RuntimeException('Duplicate destination');
        $output = @fopen($destination, 'x+b'); if ($output === false) throw new RuntimeException('Cannot create destination');
        try { self::copyEntry($stream, $output, $size, $gzip); fclose($output); @chmod($destination, 0600); }
        catch (Throwable $e) { fclose($output); @unlink($destination); throw $e; }
    }

    private static function createDirectory(string $root, string $directory): void
    {
        if (!self::isContained($root, $directory)) throw new RuntimeException('Path escapes staging');
        $relative = substr($directory, strlen($root));
        $current = $root;
        foreach (array_filter(explode('/', trim($relative, '/')), static fn (string $part): bool => $part !== '') as $part) {
            $current .= '/' . $part; $stat = @lstat($current);
            if ($stat === false) { if (!mkdir($current, 0700) || @chmod($current, 0700) === false) throw new RuntimeException('Cannot create directory'); $stat = @lstat($current); }
            if ($stat === false || ($stat['mode'] & 0170000) !== 0040000) throw new RuntimeException('Unsafe staging directory');
        }
    }

    /** @param resource $stream */
    private static function readEntry($stream, int $size, bool $gzip): string
    {
        $data = self::readExactly($stream, $size, $gzip); self::skipPadding($stream, $size, $gzip); return $data;
    }

    /** @param resource $stream */
    private static function discardEntry($stream, int $size, bool $gzip): void
    {
        $remaining = $size;
        while ($remaining > 0) { self::readExactly($stream, min(65536, $remaining), $gzip); $remaining -= min(65536, $remaining); }
        self::skipPadding($stream, $size, $gzip);
    }

    /** @param resource $stream @param resource $output */
    private static function copyEntry($stream, $output, int $size, bool $gzip): void
    {
        $remaining = $size;
        while ($remaining > 0) { $chunk = self::readExactly($stream, min(8192, $remaining), $gzip); if (fwrite($output, $chunk) !== strlen($chunk)) throw new RuntimeException('Write failure'); $remaining -= strlen($chunk); }
        self::skipPadding($stream, $size, $gzip);
    }

    /** @param resource $stream */
    private static function skipPadding($stream, int $size, bool $gzip): void { $padding = (512 - ($size % 512)) % 512; if ($padding > 0) self::readExactly($stream, $padding, $gzip); }
    /** @param resource $stream */
    private static function readExactly($stream, int $length, bool $gzip): string
    {
        $data = ''; while (strlen($data) < $length) { $chunk = $gzip ? gzread($stream, $length - strlen($data)) : fread($stream, $length - strlen($data)); if ($chunk === false || $chunk === '') throw new RuntimeException('Truncated archive'); $data .= $chunk; } return $data;
    }
    private static function field(string $header, int $offset, int $length): string { return rtrim(substr($header, $offset, $length), "\0"); }

    /** @param \finfo $finfo */
    private static function walkMedia(string $directory, string $root, \finfo $finfo): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $directory . '/' . $entry; $stat = @lstat($path);
            if ($stat === false || !self::isContained($root, $path)) throw new RuntimeException('Unsafe media');
            $kind = $stat['mode'] & 0170000;
            if ($kind === 0040000) { self::walkMedia($path, $root, $finfo); continue; }
            if ($kind !== 0100000 || $stat['size'] > self::MAX_PER_FILE_SIZE) throw new RuntimeException('Unsafe media');
            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION)); $mime = finfo_file($finfo, $path);
            if (!is_string($mime) || !isset(self::ALLOWED_IMAGE_MIMES[$mime]) || !in_array($extension, self::ALLOWED_IMAGE_MIMES[$mime], true) || @getimagesize($path) === false || !MediaPolicy::inspectStoredMedia($path, $entry)['success']) throw new RuntimeException('Invalid image');
        }
    }

    private static function isContained(string $root, string $path): bool { return $path === $root || str_starts_with($path, rtrim($root, '/') . '/'); }
    private static function isDirectory(string $path): bool { $stat = @lstat($path); return $stat !== false && ($stat['mode'] & 0170000) === 0040000; }
    private static function copyValidatedUploadsTree(string $source, string $destination): void
    {
        if (!file_exists($source) && !is_link($source)) return;
        $rootStat = @lstat($source);
        if ($rootStat === false || ($rootStat['mode'] & 0170000) !== 0040000 || !self::isContained(dirname($source), $source)) {
            throw new RuntimeException('Unsafe uploads source');
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) throw new RuntimeException('Cannot validate uploads');
        try {
            self::copyValidatedUploadsDirectory($source, $source, $destination, $finfo);
        } finally {
            unset($finfo);
        }
    }

    /** @param \finfo $finfo */
    private static function copyValidatedUploadsDirectory(string $root, string $source, string $destination, \finfo $finfo): void
    {
        $stat = @lstat($source);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000 || !self::isContained($root, $source)) {
            throw new RuntimeException('Unsafe uploads source');
        }
        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if ($entry === '.gitkeep') continue;
            if (str_starts_with($entry, '.')) throw new RuntimeException('Unsafe uploads entry');
            $from = $source . '/' . $entry;
            $to = $destination . '/' . $entry;
            $item = @lstat($from);
            if ($item === false || !self::isContained($root, $from)) throw new RuntimeException('Cannot stat uploads entry');
            $kind = $item['mode'] & 0170000;
            if ($kind === 0040000) {
                if (!mkdir($to, 0700) || @chmod($to, 0700) === false) throw new RuntimeException('Cannot create uploads directory');
                self::copyValidatedUploadsDirectory($root, $from, $to, $finfo);
                continue;
            }
            if ($kind !== 0100000 || $item['size'] > self::MAX_PER_FILE_SIZE || !self::isValidImage($from, $entry, $finfo) || !MediaPolicy::inspectStoredMedia($from, $entry)['success']) {
                throw new RuntimeException('Invalid uploads media');
            }
            $in = @fopen($from, 'rb');
            $out = @fopen($to, 'x+b');
            if ($in === false || $out === false) {
                if (is_resource($in)) fclose($in);
                if (is_resource($out)) fclose($out);
                throw new RuntimeException('Cannot copy uploads media');
            }
            try {
                if (stream_copy_to_stream($in, $out) === false || @chmod($to, 0600) === false) throw new RuntimeException('Cannot copy uploads media');
                $after = @lstat($from);
                $destinationStat = @lstat($to);
                if ($after === false || ($after['mode'] & 0170000) !== 0100000 || $after['ino'] !== $item['ino'] || $after['size'] !== $item['size'] || $destinationStat === false || ($destinationStat['mode'] & 0170000) !== 0100000) {
                    throw new RuntimeException('Uploads source changed during copy');
                }
            } finally {
                fclose($out);
                fclose($in);
            }
        }
    }

    /** @param \finfo $finfo */
    private static function isValidImage(string $path, string $entry, \finfo $finfo): bool
    {
        $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        $mime = finfo_file($finfo, $path);
        return is_string($mime) && isset(self::ALLOWED_IMAGE_MIMES[$mime]) && in_array($extension, self::ALLOWED_IMAGE_MIMES[$mime], true) && @getimagesize($path) !== false;
    }

    /** @param array<string,string> $environment */
    private static function runCommand(array $command, ?string $stdoutFile = null, array $environment = []): int
    {
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['file', $stdoutFile ?? '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
        $inheritedEnvironment = getenv();
        $env = array_merge($inheritedEnvironment, $environment);
        $process = @proc_open($command, $descriptors, $pipes, null, $env);
        if (!is_resource($process)) return 1;
        return proc_close($process);
    }

    private static function validMysqlIdentifier(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_]{1,64}$/', $value) === 1;
    }

    private static function removeTreeStrict(string $path): void
    {
        $stat = @lstat($path);
        if ($stat === false) return;
        if (($stat['mode'] & 0170000) === 0100000 || is_link($path)) {
            if (!@unlink($path)) throw new RuntimeException('Cannot remove recovery artifact');
            return;
        }
        if (($stat['mode'] & 0170000) !== 0040000) throw new RuntimeException('Unsafe recovery artifact');
        foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') self::removeTreeStrict($path . '/' . $entry);
        if (!@rmdir($path)) throw new RuntimeException('Cannot remove recovery artifact');
    }
    private static function recursiveRmDir(string $path): void
    {
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
        if (!is_dir($path)) return;
        foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') self::recursiveRmDir($path . '/' . $entry);
        @rmdir($path);
    }
}

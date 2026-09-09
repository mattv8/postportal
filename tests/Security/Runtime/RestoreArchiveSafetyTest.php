<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PostPortal\Http\ArchiveValidator;

final class RestoreArchiveSafetyTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/postportal_archive_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempDir);
    }

    public function testValidRawAndGzipArchivesExtractAndValidateMedia(): void
    {
        foreach ([false, true] as $gzip) {
            $archive = $this->archive('safe-' . (int) $gzip, [
                ['name' => 'database.sql', 'data' => 'SELECT 1;'],
                ['name' => 'uploads/', 'type' => '5'],
                ['name' => 'uploads/tiny.png', 'data' => $this->png()],
            ], $gzip);
            self::assertTrue(ArchiveValidator::validateArchiveStructure($archive)['valid']);
            $result = ArchiveValidator::extractToStaging($archive);
            self::assertTrue($result['success']);
            self::assertTrue(ArchiveValidator::validateDatabaseDump($result['staging_dir'])['valid']);
            self::assertTrue(ArchiveValidator::validateMediaFiles($result['staging_dir'])['valid']);
            ArchiveValidator::cleanupStaging($result['staging_dir']);
        }
    }

    /** @dataProvider unsafeEntryProvider */
    public function testUnsafeTarHeadersAreRejected(string $name, string $type = '0'): void
    {
        $archive = $this->archive('unsafe', [
            ['name' => 'database.sql', 'data' => 'SELECT 1;'],
            ['name' => $name, 'type' => $type, 'data' => 'x', 'link' => '/etc/passwd'],
        ]);
        self::assertFalse(ArchiveValidator::validateArchiveStructure($archive)['valid']);
        $result = ArchiveValidator::extractToStaging($archive);
        self::assertFalse($result['success']);
        self::assertArrayNotHasKey('staging_dir', $result);
    }

    public static function unsafeEntryProvider(): array
    {
        return [
            'traversal' => ['uploads/../escape.png'],
            'absolute unix' => ['/tmp/escape.png'],
            'absolute windows' => ['C:/escape.png'],
            'backslash' => ['uploads\\escape.png'],
            'symlink' => ['uploads/link.png', '2'],
            'hardlink' => ['uploads/link.png', '1'],
            'fifo' => ['uploads/pipe.png', '6'],
            'device' => ['uploads/device.png', '3'],
            'unknown root' => ['other/file.png'],
            'uploads prefix bypass' => ['uploads_evil/file.png'],
            'php' => ['uploads/shell.php'],
            'multiple extension' => ['uploads/shell.php.txt'],
            'uppercase php-like' => ['uploads/SHELL.PHP'],
            'dotfile' => ['uploads/.hidden.png'],
        ];
    }

    public function testDuplicateNormalizedAndCaseFoldedPathsAreRejected(): void
    {
        foreach ([[
            ['name' => './uploads/a.png', 'data' => $this->png()],
            ['name' => 'uploads/a.png', 'data' => $this->png()],
        ], [
            ['name' => 'uploads/A.png', 'data' => $this->png()],
            ['name' => 'uploads/a.PNG', 'data' => $this->png()],
        ]] as $entries) {
            array_unshift($entries, ['name' => 'database.sql', 'data' => 'SELECT 1;']);
            self::assertFalse(ArchiveValidator::validateArchiveStructure($this->archive('duplicate', $entries))['valid']);
        }
    }

    public function testLimitAndMalformedArchivesAreRejectedBeforeExtraction(): void
    {
        $many = [['name' => 'database.sql', 'data' => 'SELECT 1;']];
        for ($i = 0; $i <= 10000; ++$i) {
            $many[] = ['name' => 'uploads/' . $i . '.png', 'data' => ''];
        }
        self::assertFalse(ArchiveValidator::validateArchiveStructure($this->archive('many', $many))['valid']);

        $large = $this->archive('declared-large', [
            ['name' => 'database.sql', 'data' => 'SELECT 1;'],
            ['name' => 'uploads/large.png', 'size' => 201 * 1024 * 1024],
        ], false, false);
        self::assertFalse(ArchiveValidator::validateArchiveStructure($large)['valid']);

        $compressed = $this->tempDir . '/compressed-limit.tar';
        $handle = fopen($compressed, 'x+b');
        ftruncate($handle, 501 * 1024 * 1024);
        fclose($handle);
        self::assertFalse(ArchiveValidator::validateArchiveStructure($compressed)['valid']);

        $badChecksum = $this->archive('checksum', [['name' => 'database.sql', 'data' => 'SELECT 1;']], false, true);
        self::assertFalse(ArchiveValidator::validateArchiveStructure($badChecksum)['valid']);

        $badPax = $this->archive('pax', [['name' => 'pax', 'type' => 'x', 'data' => '99 path=uploads/a.png\n']]);
        self::assertFalse(ArchiveValidator::validateArchiveStructure($badPax)['valid']);
    }

    public function testMediaValidationRejectsWrongExtensionAndFakeImage(): void
    {
        // .txt extension rejected in preflight validation
        $archive = $this->archive('media-txt', [
            ['name' => 'database.sql', 'data' => 'SELECT 1;'],
            ['name' => 'uploads/tiny.txt', 'data' => $this->png()],
        ]);
        self::assertFalse(ArchiveValidator::validateArchiveStructure($archive)['valid']);
        $result = ArchiveValidator::extractToStaging($archive);
        self::assertFalse($result['success']);
        self::assertArrayNotHasKey('staging_dir', $result);

        // .png with fake bytes passes extraction, fails media validation
        $archive = $this->archive('media-fake', [
            ['name' => 'database.sql', 'data' => 'SELECT 1;'],
            ['name' => 'uploads/fake.png', 'data' => 'not an image'],
        ]);
        self::assertTrue(ArchiveValidator::validateArchiveStructure($archive)['valid']);
        $result = ArchiveValidator::extractToStaging($archive);
        self::assertTrue($result['success']);
        self::assertFalse(ArchiveValidator::validateMediaFiles($result['staging_dir'])['valid']);
        ArchiveValidator::cleanupStaging($result['staging_dir']);
    }

    public function testRootDirectoryEntryIsIgnoredWhenProperlyFormatted(): void
    {
        // Tar created from '.' has a root entry; must be ignored if it's a directory with size 0
        foreach (['.', './'] as $rootName) {
            $archive = $this->archive('root-' . bin2hex($rootName), [
                ['name' => $rootName, 'type' => '5', 'data' => ''],  // directory type, size 0
                ['name' => 'database.sql', 'data' => 'SELECT 1;'],
                ['name' => 'uploads/', 'type' => '5', 'data' => ''],
                ['name' => 'uploads/tiny.png', 'data' => $this->png()],
            ]);
            self::assertTrue(ArchiveValidator::validateArchiveStructure($archive)['valid']);
            $result = ArchiveValidator::extractToStaging($archive);
            self::assertTrue($result['success']);
            self::assertFileExists($result['staging_dir'] . '/database.sql');
            self::assertFileExists($result['staging_dir'] . '/uploads/tiny.png');
            // Root entry is not extracted as a path; only content entries
            $entries = array_diff(scandir($result['staging_dir']) ?: [], ['.', '..']);
            self::assertTrue(in_array('database.sql', $entries, true));
            self::assertTrue(in_array('uploads', $entries, true));
            self::assertCount(2, $entries);
            ArchiveValidator::cleanupStaging($result['staging_dir']);
        }
    }

    public function testValidPaxArchiveWithPathOverride(): void
    {
        // Test that PAX path override is accepted when properly formatted for uploads/
        // "25 path=uploads/file.png\n" is a valid PAX record (25 bytes total)
        $paxPayload = "25 path=uploads/file.png\n";

        $archive = $this->archive('pax-valid', [
            ['name' => 'pax-record', 'type' => 'x', 'data' => $paxPayload],
            ['name' => 'oldname.txt', 'type' => '0', 'data' => $this->png()],  // PAX overrides this name
            ['name' => 'database.sql', 'data' => 'SELECT 1;'],
            ['name' => 'uploads/', 'type' => '5'],
        ]);
        self::assertTrue(ArchiveValidator::validateArchiveStructure($archive)['valid']);
    }

    public function testMalformedPaxIsRejected(): void
    {
        // PAX record with incorrect length encoding should be rejected
        $badPaxPayload = "999 path=invalid\n";  // Length too large

        $archive = $this->archive('pax-bad-len', [
            ['name' => 'pax-record', 'type' => 'x', 'data' => $badPaxPayload],
            ['name' => 'database.sql', 'data' => 'SELECT 1;'],
            ['name' => 'uploads/', 'type' => '5'],
        ]);
        self::assertFalse(ArchiveValidator::validateArchiveStructure($archive)['valid']);
    }

    public function testLocalPaxDoesNotLeakToNextEntry(): void
    {
        // Ensure that local PAX record for one entry doesn't affect the next entry
        // First PAX record specifies path override for first entry
        $paxPayload1 = "25 path=uploads/file.png\n";
        // Second entry should NOT be affected by first PAX (nextPax is cleared after use)
        $archive = $this->archive('pax-isolation', [
            ['name' => 'pax1', 'type' => 'x', 'data' => $paxPayload1],
            ['name' => 'ignored.txt', 'type' => '0', 'data' => $this->png()],  // First PAX applies here
            ['name' => 'normal.txt', 'type' => '0', 'data' => 'regular file'],  // Should NOT be affected by PAX
            ['name' => 'database.sql', 'data' => 'SELECT 1;'],
            ['name' => 'uploads/', 'type' => '5'],
        ]);
        // This archive is invalid because 'normal.txt' has wrong extension and isn't under uploads/
        self::assertFalse(ArchiveValidator::validateArchiveStructure($archive)['valid']);
    }

    public function testOversizePaxIsRejected(): void
    {
        // PAX record exceeding MAX_METADATA_SIZE should be rejected
        $oversizePax = str_repeat('x', 2 * 1024 * 1024);  // 2 MiB

        $archive = $this->archive('pax-oversize', [
            ['name' => 'pax', 'type' => 'x', 'size' => strlen($oversizePax), 'data' => ''],
            ['name' => 'database.sql', 'data' => 'SELECT 1;'],
            ['name' => 'uploads/', 'type' => '5'],
        ], false, false);
        self::assertFalse(ArchiveValidator::validateArchiveStructure($archive)['valid']);
    }

    public function testTraversalExtractionCreatesNoTargetOutsideStaging(): void
    {
        // Ensure that extraction with traversal attempts does not create files outside staging
        $testDir = $this->tempDir . '/traversal_test_' . bin2hex(random_bytes(4));
        mkdir($testDir, 0700);

        // Try various traversal patterns (all should be rejected before extraction)
        foreach (['../../../tmp/escape.png', '....//uploads/file.png'] as $name) {
            $archive = $this->archive('traversal-marker-' . bin2hex($name), [
                ['name' => 'database.sql', 'data' => 'SELECT 1;'],
                ['name' => $name, 'data' => $this->png()],
            ]);
            $result = ArchiveValidator::extractToStaging($archive);
            self::assertFalse($result['success'], "Traversal path '$name' should be rejected");
            self::assertArrayNotHasKey('staging_dir', $result);
        }
        // Verify no spurious directories created
        $entries = array_diff(scandir($testDir) ?: [], ['.', '..']);
        self::assertEmpty($entries, 'Traversal attempts should not create files');
        rmdir($testDir);
    }

    /** @param array<int, array{name:string,type?:string,data?:string,size?:int,link?:string}> $entries */
    private function archive(string $label, array $entries, bool $gzip = true, bool $corruptChecksum = false): string
    {
        $tar = '';
        foreach ($entries as $index => $entry) {
            $data = $entry['data'] ?? '';
            $size = $entry['size'] ?? strlen($data);
            $header = $this->header($entry['name'], $size, $entry['type'] ?? '0', $entry['link'] ?? '');
            if ($corruptChecksum && $index === 0) {
                $header[0] = 'X';
            }
            $tar .= $header . $data;
            if (strlen($data) === $size) {
                $tar .= str_repeat("\0", (512 - ($size % 512)) % 512);
            }
        }
        $tar .= str_repeat("\0", 1024);
        $path = $this->tempDir . '/' . $label . ($gzip ? '.tar.gz' : '.tar');
        file_put_contents($path, $gzip ? gzencode($tar) : $tar);
        return $path;
    }

    private function header(string $name, int $size, string $type, string $link): string
    {
        $header = str_repeat("\0", 512);
        $this->put($header, 0, 100, $name);
        $this->put($header, 100, 8, "0000644\0");
        $this->put($header, 124, 12, sprintf('%011o', $size) . "\0");
        $this->put($header, 136, 12, sprintf('%011o', time()) . "\0");
        $this->put($header, 148, 8, '        ');
        $header[156] = $type;
        $this->put($header, 157, 100, $link);
        $this->put($header, 257, 6, "ustar\0");
        $this->put($header, 263, 2, '00');
        $checksum = array_sum(array_map('ord', str_split($header)));
        $this->put($header, 148, 8, sprintf("%06o\0 ", $checksum));
        return $header;
    }

    private function put(string &$target, int $offset, int $length, string $value): void
    {
        $target = substr_replace($target, substr($value, 0, $length), $offset, min($length, strlen($value)));
    }

    private function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->removeTree($path . '/' . $name);
            }
        }
        @rmdir($path);
    }
}

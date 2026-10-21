<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PostPortal\Http\ArchiveValidator;

final class RestoreWorkflowTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/postportal_restore_workflow_' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testPreparedReplacementAtomicallyReplacesEntireTreeAndCanRollback(): void
    {
        $live = $this->root . '/uploads';
        $staging = $this->root . '/staging';
        mkdir($live, 0700);
        mkdir($staging . '/uploads/nested', 0700, true);
        file_put_contents($live . '/stale.png', $this->png());
        file_put_contents($staging . '/uploads/nested/fresh.png', $this->png());
        $originalHash = hash_file('sha256', $live . '/stale.png');

        $prepared = ArchiveValidator::prepareUploadsReplacement($staging, $live);
        self::assertTrue($prepared['success']);
        self::assertFileExists($prepared['prepared_path'] . '/nested/fresh.png');
        $activation = ArchiveValidator::activateUploadsReplacement($live, $prepared['prepared_path']);
        self::assertTrue($activation['success']);
        self::assertFileDoesNotExist($live . '/stale.png');
        self::assertFileExists($live . '/nested/fresh.png');

        self::assertTrue(ArchiveValidator::rollbackUploadsReplacement($live, $activation['old_path'])['success']);
        self::assertSame($originalHash, hash_file('sha256', $live . '/stale.png'));
        self::assertFileDoesNotExist($live . '/nested/fresh.png');
    }

    public function testUnsafeMediaOrLinksCannotBePreparedOrBackedUp(): void
    {
        $live = $this->root . '/uploads';
        $staging = $this->root . '/staging';
        mkdir($live, 0700);
        mkdir($staging . '/uploads', 0700, true);
        file_put_contents($staging . '/uploads/not-image.png', 'not an image');
        self::assertFalse(ArchiveValidator::prepareUploadsReplacement($staging, $live)['success']);

        unlink($staging . '/uploads/not-image.png');
        symlink('/etc/passwd', $staging . '/uploads/link.png');
        self::assertFalse(ArchiveValidator::prepareUploadsReplacement($staging, $live)['success']);

        file_put_contents($live . '/unsafe.txt', 'unsafe');
        self::assertFalse(ArchiveValidator::copyUploadsForBackup($live, $this->root . '/backup')['success']);

        unlink($live . '/unsafe.txt');
        file_put_contents($live . '/.hidden', 'unsafe');
        self::assertFalse(ArchiveValidator::copyUploadsForBackup($live, $this->root . '/backup')['success']);
    }

    public function testOversizedImageMetadataCannotPrepareReplacementOrChangeLiveUploads(): void
    {
        $live = $this->root . '/uploads';
        $staging = $this->root . '/staging';
        mkdir($live, 0700);
        mkdir($staging . '/uploads', 0700, true);
        file_put_contents($live . '/preserve.png', $this->png());
        $originalHash = hash_file('sha256', $live . '/preserve.png');
        file_put_contents($staging . '/uploads/oversized.png', $this->oversizedPngMetadata());

        $result = ArchiveValidator::prepareUploadsReplacement($staging, $live);

        self::assertFalse($result['success']);
        self::assertSame($originalHash, hash_file('sha256', $live . '/preserve.png'));
        self::assertSame([], glob($this->root . '/.restore_new_*') ?: []);
    }

    public function testBackupCopyExcludesGitkeepAlongsideValidImages(): void
    {
        $live = $this->root . '/uploads';
        $backup = $this->root . '/backup';
        mkdir($live . '/nested', 0700, true);
        file_put_contents($live . '/.gitkeep', '');
        file_put_contents($live . '/photo.png', $this->png());
        file_put_contents($live . '/legacy-large.png', $this->largeLegacyPngMetadata());
        file_put_contents($live . '/nested/.gitkeep', '');
        file_put_contents($live . '/nested/photo.png', $this->png());

        self::assertTrue(ArchiveValidator::copyUploadsForBackup($live, $backup)['success']);
        self::assertFileExists($backup . '/photo.png');
        self::assertFileExists($backup . '/legacy-large.png');
        self::assertFileExists($backup . '/nested/photo.png');
        self::assertFileDoesNotExist($backup . '/.gitkeep');
        self::assertFileDoesNotExist($backup . '/nested/.gitkeep');
    }

    public function testBackupCopyOfRealMediaDoesNotTriggerDeprecation(): void
    {
        $live = $this->root . '/uploads';
        $backup = $this->root . '/backup';
        mkdir($live, 0700);
        file_put_contents($live . '/photo.png', $this->png());

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ($severity === E_DEPRECATED) {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            }

            return false;
        });
        try {
            $result = ArchiveValidator::copyUploadsForBackup($live, $backup);
        } finally {
            restore_error_handler();
        }

        self::assertTrue($result['success']);
        self::assertFileExists($backup . '/photo.png');
    }

    private function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);
    }

    private function oversizedPngMetadata(): string
    {
        $image = $this->png();
        return substr_replace($image, pack('NN', 8193, 1), 16, 8);
    }

    private function largeLegacyPngMetadata(): string
    {
        return substr_replace($this->png(), pack('NN', 4000, 2001), 16, 8);
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
        foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $this->remove($path . '/' . $entry);
        @rmdir($path);
    }
}

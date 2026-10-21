<?php

declare(strict_types=1);

$mediaProcessor = glob(__DIR__ . '/../../src/[Ll]ib/MediaProcessor.php');
require_once $mediaProcessor[0];

use PHPUnit\Framework\TestCase;
use PostPortal\Lib\MediaPolicy;
use PostPortal\Lib\MediaProcessor;

final class MediaPolicyTest extends TestCase
{
    public function testServerDetectedMimeMustAgreeWithFilenameExtension(): void
    {
        $fixture = $this->writeTinyPng();

        $result = MediaPolicy::inspectUpload($fixture, 'image.jpg');

        $this->assertFalse($result['success']);
        $this->assertSame('Image extension does not match server-detected MIME type', $result['error']);
    }

    public function testHeicIsRejectedBeforeAnyDecoderIsSelected(): void
    {
        $result = MediaPolicy::inspectUpload(__FILE__, 'camera.heic');

        $this->assertFalse($result['success']);
        $this->assertSame('HEIC uploads are not supported', $result['error']);
    }

    public function testCropMustStayWithinRotatedImageBoundsAndUseRightAngleRotation(): void
    {
        $invalidRotation = MediaPolicy::validateCrop(['rotate' => 45], 20, 10);
        $outsideCrop = MediaPolicy::validateCrop([
            'rotate' => 90,
            'x' => 8,
            'y' => 0,
            'width' => 3,
            'height' => 20,
        ], 20, 10);
        $validCrop = MediaPolicy::validateCrop([
            'rotate' => 90,
            'x' => 2,
            'y' => 3,
            'width' => 4,
            'height' => 10,
        ], 20, 10);

        $this->assertFalse($invalidRotation['success']);
        $this->assertFalse($outsideCrop['success']);
        $this->assertTrue($validCrop['success']);
        $this->assertSame(['rotate' => 90, 'x' => 2, 'y' => 3, 'width' => 4, 'height' => 10], $validCrop['data']);
    }

    public function testCropNormalizesLargeAndNegativeRightAngleRotationsAndRejectsInfiniteValues(): void
    {
        $largeRotation = MediaPolicy::validateCrop(['rotate' => 360000000], 20, 10);
        $negativeRotation = MediaPolicy::validateCrop(['rotate' => -90], 20, 10);
        $infiniteRotation = MediaPolicy::validateCrop(['rotate' => INF], 20, 10);

        $this->assertTrue($largeRotation['success']);
        $this->assertSame(['rotate' => 0], $largeRotation['data']);
        $this->assertTrue($negativeRotation['success']);
        $this->assertSame(['rotate' => 270], $negativeRotation['data']);
        $this->assertFalse($infiniteRotation['success']);
    }

    public function testImageBudgetAllowsPhonePhotosButRejectsTwentyFourMillionPixelsAndInsufficientMemory(): void
    {
        $phonePhoto = MediaPolicy::validateImageBudget(4032, 3024, 256 * 1024 * 1024);
        $tooManyPixels = MediaPolicy::validateImageBudget(6000, 4001, 1024 * 1024 * 1024);
        $insufficientMemory = MediaPolicy::validateImageBudget(1000, 1000, 20 * 1024 * 1024);

        $this->assertTrue($phonePhoto['success']);
        $this->assertFalse($tooManyPixels['success']);
        $this->assertSame('Image dimensions exceed the 24 million pixel limit', $tooManyPixels['error']);
        $this->assertFalse($insufficientMemory['success']);
        $this->assertSame('Insufficient memory available to process image safely', $insufficientMemory['error']);
    }

    public function testStoredCopiesKeepTheSideLimitWithoutApplyingTheProcessingPixelBudget(): void
    {
        $tooWide = MediaPolicy::inspectStoredMedia($this->writePngHeader(8193, 1), 'legacy.png');
        $phonePhoto = MediaPolicy::inspectStoredMedia($this->writePngHeader(4032, 3024), 'phone.png');
        $largeCopy = MediaPolicy::inspectStoredMedia($this->writePngHeader(6000, 4000), 'legacy.png');

        $this->assertFalse($tooWide['success']);
        $this->assertSame('Image dimensions exceed the 8192 pixel side limit', $tooWide['error']);
        $this->assertTrue($phonePhoto['success']);
        $this->assertTrue($largeCopy['success']);
    }

    public function testInvalidCropDoesNotPersistAnOriginalFile(): void
    {
        $root = $this->temporaryUploadDirectory();
        $fixture = $this->writeTinyPng();
        $processor = new MediaProcessor($root);

        $result = $processor->processUpload($this->uploadFile($fixture), '', 'admin', [
            'x' => -1,
            'y' => 0,
            'width' => 1,
            'height' => 1,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame([], glob($root . '/originals/*') ?: []);
    }

    public function testValidRightAngleCropPersistsServerDetectedMimeInsteadOfClientMime(): void
    {
        $root = $this->temporaryUploadDirectory();
        $fixture = $this->writeTinyPng();
        $processor = new MediaProcessor($root);
        $file = $this->uploadFile($fixture);
        $file['type'] = 'application/pdf';

        $result = $processor->processUpload($file, '', 'admin', [
            'rotate' => 90,
            'x' => 0,
            'y' => 0,
            'width' => 1,
            'height' => 1,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('image/png', $result['data']['mime_type']);
        $this->assertMatchesRegularExpression('/^img_[a-f0-9]{32}\.png$/', $result['data']['filename']);
    }

    public function testInvalidBrandingCropDoesNotPersistAnOriginalFile(): void
    {
        foreach (['processLogoUpload', 'processFaviconUpload'] as $method) {
            $root = $this->temporaryUploadDirectory();
            $processor = new MediaProcessor($root);

            $result = $processor->{$method}($this->uploadFile($this->writeTinyPng()), ['rotate' => 45]);

            $this->assertFalse($result['success'], $method);
            $this->assertSame([], glob($root . '/originals/*') ?: [], $method);
        }
    }

    private function writeTinyPng(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'media-policy-');
        self::assertNotFalse($path);
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAIAAAACUFjqAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADUlEQVQYlWNgGAWkAwABNgABxYufBwAAAABJRU5ErkJggg==', true));
        $this->addToAssertionCount(1);
        $this->registerCleanup($path);

        return $path;
    }

    private function writePngHeader(int $width, int $height): string
    {
        $path = $this->writeTinyPng();
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        file_put_contents($path, substr_replace($contents, pack('NN', $width, $height), 16, 8));

        return $path;
    }

    /** @return array{name: string, type: string, tmp_name: string, error: int, size: int} */
    private function uploadFile(string $path): array
    {
        return [
            'name' => 'image.png',
            'type' => 'image/png',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($path),
        ];
    }

    private function temporaryUploadDirectory(): string
    {
        $path = sys_get_temp_dir() . '/media-policy-' . bin2hex(random_bytes(8));
        mkdir($path, 0700, true);
        register_shutdown_function(static function () use ($path): void {
            exec('rm -rf ' . escapeshellarg($path));
        });

        return $path;
    }

    private function registerCleanup(string $path): void
    {
        $this->addToAssertionCount(0);
        register_shutdown_function(static fn (): bool => @unlink($path));
    }
}

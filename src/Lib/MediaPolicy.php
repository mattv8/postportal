<?php

declare(strict_types=1);

namespace PostPortal\Lib;

final class MediaPolicy
{
    public const MAX_PIXELS = 24_000_000;
    public const MAX_DIMENSION = 8192;
    private const CANVAS_COUNT = 4;
    private const BYTES_PER_PIXEL = 4;
    private const MEMORY_OVERHEAD = 16 * 1024 * 1024;

    /** @var array<string, string> */
    private const MIME_EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    /** @var array<string, string> */
    private const STORED_MIME_EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    /** @return array{success: bool, data?: array{mime: string, width: int, height: int, extension: string}, error?: string} */
    public static function inspectUpload(string $path, string $originalFilename): array
    {
        $extension = strtolower((string) pathinfo($originalFilename, PATHINFO_EXTENSION));
        if ($extension === 'heic' || $extension === 'heif') {
            return ['success' => false, 'error' => 'HEIC uploads are not supported'];
        }
        return self::inspectImage($path, $originalFilename, self::MIME_EXTENSIONS);
    }

    /** @return array{success: bool, data?: array{mime: string, width: int, height: int, extension: string}, error?: string} */
    public static function inspectStoredMedia(string $path, string $storedFilename): array
    {
        return self::inspectImage($path, $storedFilename, self::STORED_MIME_EXTENSIONS);
    }

    /** @return array{success: bool, data?: array{mime: string, width: int, height: int, extension: string}, error?: string} */
    public static function inspectForProcessing(string $path, string $storedFilename): array
    {
        $inspection = self::inspectStoredMedia($path, $storedFilename);
        if (!$inspection['success']) {
            return $inspection;
        }

        $budget = self::validateImageBudget(
            $inspection['data']['width'],
            $inspection['data']['height'],
            self::availableMemory(),
        );

        return $budget['success'] ? $inspection : $budget;
    }

    /**
     * @param array<string, string> $allowedMimeExtensions
     * @return array{success: bool, data?: array{mime: string, width: int, height: int, extension: string}, error?: string}
     */
    private static function inspectImage(string $path, string $filename, array $allowedMimeExtensions): array
    {
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo === false ? false : finfo_file($finfo, $path);
        unset($finfo);
        if (!is_string($mime) || !isset($allowedMimeExtensions[$mime])) {
            return ['success' => false, 'error' => 'Invalid server-detected image format'];
        }
        $expectedExtension = $allowedMimeExtensions[$mime];
        if ($extension !== $expectedExtension && !($mime === 'image/jpeg' && $extension === 'jpeg')) {
            return ['success' => false, 'error' => 'Image extension does not match server-detected MIME type'];
        }
        $imageInfo = @getimagesize($path);
        if ($imageInfo === false || $imageInfo['mime'] !== $mime) {
            return ['success' => false, 'error' => 'File is not a valid image'];
        }
        $dimensions = self::validateDimensions((int) $imageInfo[0], (int) $imageInfo[1]);
        if (!$dimensions['success']) {
            return $dimensions;
        }
        if ($allowedMimeExtensions === self::MIME_EXTENSIONS) {
            $budget = self::validateImageBudget((int) $imageInfo[0], (int) $imageInfo[1], self::availableMemory());
            if (!$budget['success']) {
                return $budget;
            }
        }
        return ['success' => true, 'data' => ['mime' => $mime, 'width' => (int) $imageInfo[0], 'height' => (int) $imageInfo[1], 'extension' => $expectedExtension]];
    }

    /** @return array{success: bool, error?: string} */
    public static function validateImageBudget(int $width, int $height, int $availableMemory): array
    {
        $dimensions = self::validateDimensions($width, $height);
        if (!$dimensions['success']) {
            return $dimensions;
        }
        if ($width * $height > self::MAX_PIXELS) {
            return ['success' => false, 'error' => 'Image dimensions exceed the 24 million pixel limit'];
        }
        $required = ($width * $height * self::BYTES_PER_PIXEL * self::CANVAS_COUNT) + self::MEMORY_OVERHEAD;
        return $required > $availableMemory
            ? ['success' => false, 'error' => 'Insufficient memory available to process image safely']
            : ['success' => true];
    }

    /** @return array{success: bool, error?: string} */
    public static function validateDimensions(int $width, int $height): array
    {
        return $width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION
            ? ['success' => false, 'error' => 'Image dimensions exceed the 8192 pixel side limit']
            : ['success' => true];
    }

    /**
     * @param array<string, mixed>|null $crop
     * @return array{success: bool, data?: array{rotate: int, x?: int, y?: int, width?: int, height?: int}, error?: string}
     */
    public static function validateCrop(?array $crop, int $sourceWidth, int $sourceHeight): array
    {
        if ($crop === null || $crop === []) {
            return ['success' => true, 'data' => ['rotate' => 0]];
        }
        $rotate = self::integerValue($crop['rotate'] ?? 0);
        if ($rotate === null || $rotate % 90 !== 0) {
            return ['success' => false, 'error' => 'Rotation must be a multiple of 90 degrees'];
        }
        $rotate = (($rotate % 360) + 360) % 360;
        $hasCrop = array_key_exists('x', $crop) || array_key_exists('y', $crop) || array_key_exists('width', $crop) || array_key_exists('height', $crop);
        if (!$hasCrop) {
            return ['success' => true, 'data' => ['rotate' => $rotate]];
        }
        $x = self::integerValue($crop['x'] ?? null);
        $y = self::integerValue($crop['y'] ?? null);
        $width = self::integerValue($crop['width'] ?? null);
        $height = self::integerValue($crop['height'] ?? null);
        if ($x === null || $y === null || $width === null || $height === null || $x < 0 || $y < 0 || $width < 1 || $height < 1) {
            return ['success' => false, 'error' => 'Crop coordinates must be finite positive image bounds'];
        }
        $rotated = abs($rotate / 90) % 2 === 1;
        $imageWidth = $rotated ? $sourceHeight : $sourceWidth;
        $imageHeight = $rotated ? $sourceWidth : $sourceHeight;
        if ($x > $imageWidth || $y > $imageHeight || $width > $imageWidth - $x || $height > $imageHeight - $y) {
            return ['success' => false, 'error' => 'Crop coordinates exceed image bounds'];
        }
        return ['success' => true, 'data' => compact('rotate', 'x', 'y', 'width', 'height')];
    }

    public static function randomFilename(string $prefix, string $mime): string
    {
        return $prefix . bin2hex(random_bytes(16)) . '.' . self::MIME_EXTENSIONS[$mime];
    }

    private static function availableMemory(): int
    {
        $limit = ini_get('memory_limit');
        if (!is_string($limit) || $limit === '-1') {
            return PHP_INT_MAX;
        }
        $unit = strtolower(substr($limit, -1));
        $value = (int) $limit;
        $bytes = match ($unit) {'g' => $value * 1024 * 1024 * 1024, 'm' => $value * 1024 * 1024, 'k' => $value * 1024, default => $value};
        return max(0, $bytes - memory_get_usage(true));
    }

    private static function integerValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?(0|[1-9][0-9]*)$/', $value) === 1) {
            $integer = (int) $value;
            return (string) $integer === $value ? $integer : null;
        }
        if (!is_float($value) || !is_finite($value) || floor($value) !== $value || $value < PHP_INT_MIN || $value > PHP_INT_MAX) {
            return null;
        }
        return (int) $value;
    }
}

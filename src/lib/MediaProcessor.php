<?php

declare(strict_types=1);

namespace PostPortal\Lib;

use Exception;
use Intervention\Image\ImageManager;

/**
 * Media Processing Library
 * Handles image uploads, optimization, and responsive variant generation
 */

// Conditionally load Composer autoload only if not already loaded
if (!class_exists('Composer\\Autoload\\ClassLoader', false)) {
    require_once(__DIR__ . '/../vendor/autoload.php');
}

class MediaProcessor
{
    private string $uploadsDir;
    private string $originalsDir;
    private string $variantsDir;
    private string $webRoot;
    private int $maxFileSize = 20971520; // 20MB in bytes
    /** @var array<string> */
    private array $allowedFormats = ['image/jpeg', 'image/png', 'image/heic', 'image/webp'];
    /** @var array<string> */
    private array $allowedExtensions = ['jpg', 'jpeg', 'png', 'heic', 'webp'];
    /** @var array<int> */
    private array $variantWidths = [1600, 800, 400];
    private ImageManager $manager;

    public function __construct(?string $baseDir = null)
    {
        if ($baseDir === null) {
            $baseDir = __DIR__ . '/../storage/uploads';
        }

        // Normalize the base directory path and web root
        $this->uploadsDir = rtrim(realpath($baseDir) ?: $baseDir, '/');
        $this->originalsDir = $this->uploadsDir . '/originals';
        $this->variantsDir = $this->uploadsDir . '/variants';

        // Determine web root - go up from lib to src directory
        $this->webRoot = rtrim(realpath(__DIR__ . '/..') ?: __DIR__ . '/..', '/');

        $this->manager = new ImageManager(['driver' => 'gd']);

        $this->ensureDirectories();
    }

    /**
     * Safely write file contents with contextual logging.
     */
    private function writeFile(string $path, string $data): void
    {
        if (@file_put_contents($path, $data) === false) {
            error_log('Failed to write file: ' . $path);
            throw new Exception('Failed to write file to disk');
        }
    }

    /**
     * Safely move an uploaded file, falling back to copy when needed.
     */
    private function moveUploadedFile(string $tmpPath, string $destination): void
    {
        if (@move_uploaded_file($tmpPath, $destination)) {
            return;
        }

        if (@copy($tmpPath, $destination)) {
            return;
        }

        error_log('Failed to move uploaded file to ' . $destination);
        throw new Exception('Failed to persist uploaded file');
    }

    /**
     * Delete a file if it exists, throwing when deletion fails.
     */
    private function unlinkIfExists(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (!@unlink($path)) {
            error_log('Failed to delete file: ' . $path);
            throw new Exception('Unable to delete file');
        }
    }

    /**
     * Ensure all required directories exist
     */
    private function ensureDirectories(): void
    {
        $dirs = [
            $this->uploadsDir,
            $this->originalsDir,
            $this->variantsDir
        ];

        foreach ($this->variantWidths as $width) {
            $dirs[] = $this->variantsDir . '/' . $width;
        }

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0775, true)) {
                    $error = error_get_last();
                    error_log("Failed to create directory: {$dir}");
                    error_log("Error: " . ($error['message'] ?? 'Unknown error'));
                    error_log("Parent directory writable: " . (is_writable(dirname($dir)) ? 'yes' : 'no'));
                    error_log("Current user: " . get_current_user());
                    throw new Exception("Failed to create upload directory. Please ensure storage/uploads is writable by the web server.");
                }
            }

            // Verify directory is writable
            if (!is_writable($dir)) {
                error_log("Directory exists but is not writable: {$dir}");
                error_log("Directory permissions: " . substr(sprintf('%o', fileperms($dir)), -4));
                $ownerInfo = posix_getpwuid(fileowner($dir));
                $ownerName = ($ownerInfo !== false) ? $ownerInfo['name'] : 'unknown';
                error_log("Directory owner: " . $ownerName);
                error_log("Current user: " . get_current_user());
                throw new Exception("Upload directory is not writable. Please check permissions on storage/uploads.");
            }
        }
    }

    /**
     * Convert an absolute filesystem path to a relative web path
     * @param string $absolutePath Full filesystem path
     * @return string Relative path from web root (e.g., 'storage/uploads/...')
     */
    private function toRelativePath(string $absolutePath): string
    {
        // Normalize the absolute path
        $normalized = realpath($absolutePath) ?: $absolutePath;

        // Remove the web root prefix
        if (strpos($normalized, $this->webRoot) === 0) {
            $relative = substr($normalized, strlen($this->webRoot) + 1);
            return str_replace('\\', '/', $relative); // Normalize directory separators
        }

        // Fallback: try to extract storage/uploads/... pattern
        if (preg_match('#(storage/uploads/.+)$#', str_replace('\\', '/', $absolutePath), $matches)) {
            return $matches[1];
        }

        // Last resort: return as-is (shouldn't happen in normal operation)
        return str_replace('\\', '/', $absolutePath);
    }

    /**
     * Process an uploaded file
     * @param array<string, mixed> $file $_FILES array element
     * @param string $altText Optional alt text
     * @param string $username Username of uploader
     * @param array<string, int>|null $cropData Optional crop coordinates (x, y, width, height)
     * @return array{success: bool, data?: array<string, mixed>, error?: string}
     */
    public function processUpload(array $file, string $altText = '', string $username = 'admin', ?array $cropData = null): array
    {
        error_log('MediaProcessor: upload start for user ' . $username . ' file ' . ($file['name'] ?? 'unknown') . ' type ' . ($file['type'] ?? 'n/a') . ' size ' . ($file['size'] ?? 'n/a'));
        // Validate file upload
        $validation = $this->validateUpload($file);
        if (!$validation['success']) {
            error_log('MediaProcessor: validation failed - ' . ($validation['error'] ?? 'unknown error'));
            return $validation;
        }

        try {
            // Generate unique filename
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $uniqueFilename = uniqid('img_' . time() . '_') . '.' . $extension;
            $originalPath = $this->originalsDir . '/' . $uniqueFilename;

            // Handle HEIC conversion
            if ($file['type'] === 'image/heic' || $extension === 'heic') {
                $converted = $this->convertHeicToJpeg($file['tmp_name']);
                if ($converted['success']) {
                    $uniqueFilename = str_replace('.heic', '.jpg', $uniqueFilename);
                    $originalPath = $this->originalsDir . '/' . $uniqueFilename;
                    $this->writeFile($originalPath, $converted['data']);
                    $file['type'] = 'image/jpeg';
                } else {
                    return ['success' => false, 'error' => 'Failed to convert HEIC image'];
                }
            } else {
                // Move uploaded file to originals directory
                // Use copy() as fallback for CLI/seed scripts where move_uploaded_file() fails
                $this->moveUploadedFile($file['tmp_name'], $originalPath);
            }

            // Apply crop if provided
            if ($cropData && isset($cropData['width']) && $cropData['width'] > 0) {
                $img = $this->manager->make($originalPath);

                // Apply rotation first if specified (before crop coordinates are applied)
                if (isset($cropData['rotate']) && (int)$cropData['rotate'] !== 0) {
                    // Cropper.js uses counter-clockwise rotation, Intervention uses clockwise
                    // So we negate the rotation angle
                    $img->rotate(-(int)$cropData['rotate']);
                }

                $img->crop(
                    (int)$cropData['width'],
                    (int)$cropData['height'],
                    (int)$cropData['x'],
                    (int)$cropData['y']
                );
                $img->save($originalPath, 90);
            } elseif ($cropData && isset($cropData['rotate']) && (int)$cropData['rotate'] !== 0) {
                // Apply rotation only (no crop)
                $img = $this->manager->make($originalPath);
                $img->rotate(-(int)$cropData['rotate']);
                $img->save($originalPath, 90);
            }

            // Strip EXIF data for privacy (keep orientation)
            $this->stripExifData($originalPath);

            // Get image dimensions
            $imageInfo = getimagesize($originalPath);
            $width = $imageInfo[0];
            $height = $imageInfo[1];

            // Generate responsive variants
            $variants = $this->generateVariants($originalPath, $uniqueFilename);

            // Prepare media record data
            $mediaData = [
                'filename' => $uniqueFilename,
                'original_filename' => $file['name'],
                'mime_type' => $file['type'],
                'size_bytes' => filesize($originalPath),
                'width' => $width,
                'height' => $height,
                'alt_text' => $altText,
                'storage_path' => $this->toRelativePath($originalPath),
                'variants_json' => json_encode($variants),
                'created_by_user_id' => $username
            ];

            return [
                'success' => true,
                'data' => $mediaData
            ];

        } catch (Exception $e) {
            error_log("Media processing error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to process image: ' . $e->getMessage()];
        }
    }

    /**
     * Validate uploaded file
     * @param array<string, mixed> $file
     * @return array{success: bool, error?: string}
     */
    private function validateUpload(array $file): array
    {
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $err = 'File upload error: ' . $this->getUploadError($file['error']);
            error_log('MediaProcessor: ' . $err);
            return ['success' => false, 'error' => $err];
        }

        // Check file size
        if ($file['size'] > $this->maxFileSize) {
            $err = 'File size exceeds 20MB limit';
            error_log('MediaProcessor: ' . $err);
            return ['success' => false, 'error' => $err];
        }

        // Validate MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $this->allowedFormats) && !in_array($file['type'], $this->allowedFormats)) {
            $err = 'Invalid file format. Only JPG, PNG, HEIC, and WebP are allowed';
            error_log('MediaProcessor: ' . $err . ' (mime: ' . $mimeType . ', type: ' . ($file['type'] ?? 'n/a') . ')');
            return ['success' => false, 'error' => $err];
        }

        // Validate extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $this->allowedExtensions)) {
            $err = 'Invalid file extension';
            error_log('MediaProcessor: ' . $err . ' (' . $extension . ')');
            return ['success' => false, 'error' => $err];
        }

        // Check if file is actually an image
        if (!getimagesize($file['tmp_name']) && $extension !== 'heic') {
            $err = 'File is not a valid image';
            error_log('MediaProcessor: ' . $err);
            return ['success' => false, 'error' => $err];
        }

        return ['success' => true];
    }

    /**
     * Generate responsive variants of an image
     * @return array<array{width: int, path: string, format: string}>
     */
    private function generateVariants(string $originalPath, string $filename): array
    {
        $variants = [];
        $baseFilename = pathinfo($filename, PATHINFO_FILENAME);

        foreach ($this->variantWidths as $width) {
            try {
                // Load original image
                $img = $this->manager->make($originalPath);

                // Only resize if image is wider than target width
                if ($img->width() > $width) {
                    $img->resize($width, null, function ($constraint) {
                        $constraint->aspectRatio();
                        $constraint->upsize();
                    });
                }

                // Save JPEG/PNG variant
                $variantFilename = $baseFilename . '_' . $width . 'w.jpg';
                $variantPath = $this->variantsDir . '/' . $width . '/' . $variantFilename;
                $img->save($variantPath, 85);

                $variants[] = [
                    'width' => $width,
                    'path' => $this->toRelativePath($variantPath),
                    'format' => 'jpg'
                ];

                // Generate WebP variant using CLI tool (if available)
                $webpFilename = $baseFilename . '_' . $width . 'w.webp';
                $webpPath = $this->variantsDir . '/' . $width . '/' . $webpFilename;

                if ($this->convertToWebP($variantPath, $webpPath)) {
                    $variants[] = [
                        'width' => $width,
                        'path' => $this->toRelativePath($webpPath),
                        'format' => 'webp'
                    ];
                }

                // Optimize JPEG
                $this->optimizeJpeg($variantPath);

            } catch (Exception $e) {
                error_log("Variant generation error for width {$width}: " . $e->getMessage());
            }
        }

        return $variants;
    }

    /**
     * Convert image to WebP format using CLI tool
     */
    private function convertToWebP(string $source, string $destination): bool
    {
        // Check if cwebp is available
        exec('which cwebp 2>&1', $output, $returnCode);
        if ($returnCode !== 0) {
            return false;
        }

        $command = sprintf(
            'cwebp -q 85 %s -o %s 2>&1',
            escapeshellarg($source),
            escapeshellarg($destination)
        );

        exec($command, $output, $returnCode);

        return $returnCode === 0 && file_exists($destination);
    }

    /**
     * Optimize JPEG file using jpegoptim
     */
    private function optimizeJpeg(string $filepath): bool
    {
        exec('which jpegoptim 2>&1', $output, $returnCode);
        if ($returnCode !== 0) {
            return false;
        }

        $command = sprintf(
            'jpegoptim --strip-all --max=85 %s 2>&1',
            escapeshellarg($filepath)
        );

        exec($command, $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Strip EXIF data from image (keep orientation)
     */
    private function stripExifData(string $filepath): bool
    {
        try {
            $img = $this->manager->make($filepath);

            // Auto-orient based on EXIF before stripping
            $img->orientate();

            // Save without EXIF
            $img->save($filepath);

            return true;
        } catch (Exception $e) {
            error_log("EXIF stripping error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Convert HEIC to JPEG
     * Note: Requires imagick extension with HEIC support
     *
     * @param string $heicPath Path to HEIC file
     * @return array{success: bool, data?: string, error?: string}
     */
    private function convertHeicToJpeg(string $heicPath): array
    {
        if (!extension_loaded('imagick')) {
            return ['success' => false, 'error' => 'Imagick extension not available'];
        }

        try {
            $imagick = new \Imagick($heicPath);
            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality(90);
            $jpegData = $imagick->getImageBlob();
            $imagick->clear();
            $imagick->destroy();

            return ['success' => true, 'data' => $jpegData];
        } catch (Exception $e) {
            error_log("HEIC conversion error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Regenerate variants for an existing original image
     *
     * @param string $originalPath Path to original image
     * @param string $filename Original filename
     * @return array{success: bool, variants?: array<array{width: int, path: string, format: string}>, error?: string}
     */
    public function regenerateVariants(string $originalPath, string $filename): array
    {
        $fullPath = $this->originalsDir . '/' . $filename;

        if (!file_exists($fullPath)) {
            return ['success' => false, 'error' => 'Original file not found'];
        }

        try {
            $variants = $this->generateVariants($fullPath, $filename);
            return [
                'success' => true,
                'variants' => $variants
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Delete media file and all variants
     *
     * @param string $filename Original filename
     * @param string|null $variantsJson JSON string of variant paths
     * @return array{success: bool, error?: string}
     */
    public function deleteMedia(string $filename, ?string $variantsJson): array
    {
        $originalPath = $this->originalsDir . '/' . $filename;
        try {
            // Delete original
            $this->unlinkIfExists($originalPath);

            // Delete variants
            $variants = json_decode($variantsJson, true);
            if (is_array($variants)) {
                foreach ($variants as $variant) {
                    $variantPath = __DIR__ . '/../' . $variant['path'];
                    $this->unlinkIfExists($variantPath);
                }
            }

            return ['success' => true];
        } catch (Exception $e) {
            error_log("Media deletion error for {$filename}: " . $e->getMessage());
            error_log('Tried original at: ' . $originalPath);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get upload error message
     *
     * @param int $code PHP upload error code
     * @return string Human-readable error message
     */
    private function getUploadError(int $code): string
    {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'File only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
        ];

        return $errors[$code] ?? 'Unknown upload error';
    }

    /**
     * Generate srcset attribute for responsive images
     *
     * @param string|array<array{width: int, path: string, format: string}>|null $variants Variants data (JSON string or array)
     * @param string $format Image format to filter by (jpg, webp, png)
     * @return string Srcset attribute value
     */
    public static function generateSrcset(string|array|null $variants, string $format = 'jpg'): string
    {
        if (empty($variants)) {
            return '';
        }

        $variantsArray = is_string($variants) ? json_decode($variants, true) : $variants;
        $srcsetParts = [];

        foreach ($variantsArray as $variant) {
            if ($variant['format'] === $format) {
                // Paths are already stored as relative from web root (e.g., 'storage/uploads/...')
                $path = '/' . ltrim($variant['path'], '/');
                $srcsetParts[] = $path . ' ' . $variant['width'] . 'w';
            }
        }

        return implode(', ', $srcsetParts);
    }

    /**
     * Process logo upload with optional cropping
     *
     * @param array<string, mixed> $file $_FILES array element
     * @param array<string, int>|null $cropData Optional crop coordinates (x, y, width, height)
     * @param string $username Username of uploader
     * @return array{success: bool, data?: array<string, mixed>, error?: string}
     */
    public function processLogoUpload(array $file, ?array $cropData = null, string $username = 'admin'): array
    {
        // Validate file upload
        $validation = $this->validateUpload($file);
        if (!$validation['success']) {
            return $validation;
        }

        try {
            // Generate unique filename
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $uniqueFilename = 'logo_' . uniqid(time() . '_') . '.' . $extension;
            $originalPath = $this->originalsDir . '/' . $uniqueFilename;

            // Handle HEIC conversion
            if ($file['type'] === 'image/heic' || $extension === 'heic') {
                $converted = $this->convertHeicToJpeg($file['tmp_name']);
                if ($converted['success']) {
                    $uniqueFilename = str_replace('.heic', '.jpg', $uniqueFilename);
                    $originalPath = $this->originalsDir . '/' . $uniqueFilename;
                    $this->writeFile($originalPath, $converted['data']);
                    $file['type'] = 'image/jpeg';
                } else {
                    return ['success' => false, 'error' => 'Failed to convert HEIC image'];
                }
            } else {
                // Move uploaded file to originals directory
                // Use copy() as fallback for CLI/seed scripts where move_uploaded_file() fails
                $this->moveUploadedFile($file['tmp_name'], $originalPath);
            }

            // Load image for cropping
            $img = $this->manager->make($originalPath);

            // Apply rotation first if specified
            if ($cropData && isset($cropData['rotate']) && (int)$cropData['rotate'] !== 0) {
                $img->rotate(-(int)$cropData['rotate']);
                $img->save($originalPath, 90);
                // Reload image after rotation for accurate crop coordinates
                $img = $this->manager->make($originalPath);
            }

            // Apply crop if provided
            if ($cropData && isset($cropData['width']) && $cropData['width'] > 0) {
                $img->crop(
                    (int)$cropData['width'],
                    (int)$cropData['height'],
                    (int)$cropData['x'],
                    (int)$cropData['y']
                );
                $img->save($originalPath, 90);
            }

            // Strip EXIF data
            $this->stripExifData($originalPath);

            // Get final dimensions
            $imageInfo = getimagesize($originalPath);
            $width = $imageInfo[0];
            $height = $imageInfo[1];

            // Generate logo variants (standard widths + retina)
            $variants = $this->generateLogoVariants($originalPath, $uniqueFilename);

            $mediaData = [
                'filename' => $uniqueFilename,
                'original_filename' => $file['name'],
                'mime_type' => $file['type'],
                'size_bytes' => filesize($originalPath),
                'width' => $width,
                'height' => $height,
                'alt_text' => 'Site Logo',
                'storage_path' => $this->toRelativePath($originalPath),
                'variants_json' => json_encode($variants),
                'created_by_user_id' => $username
            ];

            return [
                'success' => true,
                'data' => $mediaData
            ];

        } catch (Exception $e) {
            error_log("Logo processing error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to process logo: ' . $e->getMessage()];
        }
    }

    /**
     * Process favicon upload with cropping and generate multiple sizes
     *
     * @param array<string, mixed> $file $_FILES array element
     * @param array<string, int>|null $cropData Optional crop coordinates (x, y, width, height)
     * @param string $username Username of uploader
     * @return array{success: bool, data?: array<string, mixed>, error?: string}
     */
    public function processFaviconUpload(array $file, ?array $cropData = null, string $username = 'admin'): array
    {
        // Validate file upload
        $validation = $this->validateUpload($file);
        if (!$validation['success']) {
            return $validation;
        }

        try {
            // Generate unique filename
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $uniqueFilename = 'favicon_' . uniqid(time() . '_') . '.' . $extension;
            $originalPath = $this->originalsDir . '/' . $uniqueFilename;

            // Handle HEIC conversion
            if ($file['type'] === 'image/heic' || $extension === 'heic') {
                $converted = $this->convertHeicToJpeg($file['tmp_name']);
                if ($converted['success']) {
                    $uniqueFilename = str_replace('.heic', '.jpg', $uniqueFilename);
                    $originalPath = $this->originalsDir . '/' . $uniqueFilename;
                    $this->writeFile($originalPath, $converted['data']);
                } else {
                    return ['success' => false, 'error' => 'Failed to convert HEIC image'];
                }
            } else {
                // Use copy() as fallback for CLI/seed scripts where move_uploaded_file() fails
                $this->moveUploadedFile($file['tmp_name'], $originalPath);
            }

            // Load image for cropping
            $img = $this->manager->make($originalPath);

            // Apply rotation first if specified
            if ($cropData && isset($cropData['rotate']) && (int)$cropData['rotate'] !== 0) {
                $img->rotate(-(int)$cropData['rotate']);
                $img->save($originalPath, 90);
                // Reload image after rotation for accurate crop coordinates
                $img = $this->manager->make($originalPath);
            }

            // Apply crop if provided
            if ($cropData && isset($cropData['width']) && $cropData['width'] > 0) {
                $img->crop(
                    (int)$cropData['width'],
                    (int)$cropData['height'],
                    (int)$cropData['x'],
                    (int)$cropData['y']
                );
            }

            // Ensure square aspect ratio for favicon
            $size = min($img->width(), $img->height());
            $img->crop($size, $size,
                (int)(($img->width() - $size) / 2),
                (int)(($img->height() - $size) / 2)
            );

            $img->save($originalPath, 90);

            // Strip EXIF data
            $this->stripExifData($originalPath);

            // Get final dimensions
            $imageInfo = getimagesize($originalPath);
            $width = $imageInfo[0];
            $height = $imageInfo[1];

            // Generate favicon variants (16, 32, 48, 192, 512)
            $variants = $this->generateFaviconVariants($originalPath, $uniqueFilename);

            $mediaData = [
                'filename' => $uniqueFilename,
                'original_filename' => $file['name'],
                'mime_type' => 'image/png',
                'size_bytes' => filesize($originalPath),
                'width' => $width,
                'height' => $height,
                'alt_text' => 'Site Favicon',
                'storage_path' => $this->toRelativePath($originalPath),
                'variants_json' => json_encode($variants),
                'created_by_user_id' => $username
            ];

            return [
                'success' => true,
                'data' => $mediaData
            ];

        } catch (Exception $e) {
            error_log("Favicon processing error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to process favicon: ' . $e->getMessage()];
        }
    }

    /**
     * Generate logo variants for different screen sizes
     *
     * @param string $originalPath Path to original image
     * @param string $filename Original filename
     * @return array<array{width: int, path: string, format: string}>
     */
    private function generateLogoVariants(string $originalPath, string $filename): array
    {
        $variants = [];
        $baseFilename = pathinfo($filename, PATHINFO_FILENAME);

        // Logo sizes: 200px, 400px for standard and retina displays
        $logoWidths = [200, 400];

        foreach ($logoWidths as $width) {
            try {
                $img = $this->manager->make($originalPath);

                // Resize maintaining aspect ratio
                if ($img->width() > $width) {
                    $img->resize($width, null, function ($constraint) {
                        $constraint->aspectRatio();
                        $constraint->upsize();
                    });
                }

                // Create width-specific directory if needed
                $widthDir = $this->variantsDir . '/' . $width;
                if (!is_dir($widthDir)) {
                    mkdir($widthDir, 0775, true);
                }

                // Save PNG variant (logos need transparency)
                $variantFilename = $baseFilename . '_' . $width . 'w.png';
                $variantPath = $widthDir . '/' . $variantFilename;
                $img->save($variantPath, 90);

                $variants[] = [
                    'width' => $width,
                    'path' => $this->toRelativePath($variantPath),
                    'format' => 'png'
                ];

                // Also save WebP
                $webpFilename = $baseFilename . '_' . $width . 'w.webp';
                $webpPath = $widthDir . '/' . $webpFilename;

                if ($this->convertToWebP($variantPath, $webpPath)) {
                    $variants[] = [
                        'width' => $width,
                        'path' => $this->toRelativePath($webpPath),
                        'format' => 'webp'
                    ];
                }

            } catch (Exception $e) {
                error_log("Logo variant generation error for width {$width}: " . $e->getMessage());
            }
        }

        return $variants;
    }

    /**
     * Generate favicon variants in multiple sizes
     *
     * @param string $originalPath Path to original image
     * @param string $filename Original filename
     * @return array<array{size: int|string, path: string, format: string}>
     */
    private function generateFaviconVariants(string $originalPath, string $filename): array
    {
        $variants = [];
        $baseFilename = pathinfo($filename, PATHINFO_FILENAME);

        // Favicon sizes: 16, 32, 48, 192, 512
        $faviconSizes = [16, 32, 48, 192, 512];

        foreach ($faviconSizes as $size) {
            try {
                $img = $this->manager->make($originalPath);

                // Resize to exact square size
                $img->resize($size, $size);

                // Save PNG variant
                $variantFilename = $baseFilename . '_' . $size . 'x' . $size . '.png';
                $variantPath = $this->variantsDir . '/' . $size . '/' . $variantFilename;

                // Create size-specific directory if needed
                if (!is_dir($this->variantsDir . '/' . $size)) {
                    mkdir($this->variantsDir . '/' . $size, 0775, true);
                }

                $img->save($variantPath, 90);

                $variants[] = [
                    'size' => $size,
                    'path' => $this->toRelativePath($variantPath),
                    'format' => 'png'
                ];

            } catch (Exception $e) {
                error_log("Favicon variant generation error for size {$size}: " . $e->getMessage());
            }
        }

        // Generate .ico file from 16x16, 32x32, 48x48
        try {
            $icoFilename = $baseFilename . '.ico';
            $icoPath = $this->uploadsDir . '/' . $icoFilename;

            if ($this->generateIcoFile($originalPath, $icoPath)) {
                $variants[] = [
                    'size' => 'multi',
                    'path' => $this->toRelativePath($icoPath),
                    'format' => 'ico'
                ];
            }
        } catch (Exception $e) {
            error_log("ICO generation error: " . $e->getMessage());
        }

        return $variants;
    }

    /**
     * Generate .ico file from PNG (using imagemagick convert if available)
     *
     * @param string $sourcePath Path to source PNG image
     * @param string $icoPath Path where ICO file will be saved
     * @return bool True if ICO file was created successfully
     */
    private function generateIcoFile(string $sourcePath, string $icoPath): bool
    {
        // Check if ImageMagick convert is available
        exec('which convert 2>&1', $output, $returnCode);

        if ($returnCode === 0) {
            // Use ImageMagick to create multi-resolution .ico
            $cmd = sprintf(
                'convert %s -resize 16x16 -define icon:auto-resize=16,32,48 %s 2>&1',
                escapeshellarg($sourcePath),
                escapeshellarg($icoPath)
            );
            exec($cmd, $output, $returnCode);

            return $returnCode === 0 && file_exists($icoPath);
        }

        // Fallback: just copy the 32x32 PNG as .ico (browsers will handle it)
        $img = $this->manager->make($sourcePath);
        $img->resize(32, 32);
        $img->save($icoPath);

        return file_exists($icoPath);
    }

    /**
     * Auto-detect content bounds and return crop coordinates
     * Detects and removes whitespace around the image
     *
     * @param string $imagePath Path to image file
     * @return array{x: int, y: int, width: int, height: int, detected: bool, error?: string}
     */
    public function detectContentBounds(string $imagePath): array
    {
        try {
            $img = $this->manager->make($imagePath);

            // Trim whitespace/transparent pixels
            $img->trim('transparent', [], 1);

            // For now, return the full dimensions after trim
            // In a real implementation, we'd compare with original to get coordinates
            // This is a simplified version - the actual trim happens in the cropping step

            return [
                'x' => 0,
                'y' => 0,
                'width' => $img->width(),
                'height' => $img->height(),
                'detected' => true
            ];

        } catch (Exception $e) {
            error_log("Content bounds detection error: " . $e->getMessage());
            return [
                'x' => 0,
                'y' => 0,
                'width' => 0,
                'height' => 0,
                'detected' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}

<?php

declare(strict_types=1);

/*
    Custom PHP Functions
*/
// Conditionally load Composer autoload only if not already loaded
if (!class_exists('Composer\\Autoload\\ClassLoader', false)) {
    require_once(__DIR__ . '/vendor/autoload.php'); // Composer autoload
}
require_once(__DIR__ . '/lib/MediaProcessor.php');
require_once(__DIR__ . '/Http/ViewRenderer.php');

use PostPortal\Http\ViewRenderer;
use PostPortal\Lib\MediaProcessor;

// Default AI system prompt for title generation
define('DEFAULT_AI_SYSTEM_PROMPT', 'You are a helpful assistant that creates concise, engaging titles for update posts. The title should be short (3-8 words), and capture the essence of the update. Return ONLY the title text, nothing else.');

/**
 * Get database connection.
 *
 * @param string $host Database host
 * @param string $user Database user
 * @param string $password Database password
 * @param string $database Database name
 * @return \mysqli|null Database connection or null on failure
 */
function getDbConnection(string $host, string $user, string $password, string $database): ?\mysqli
{
    $conn = mysqli_connect($host, $user, $password, $database);
    if (!$conn) {
        error_log('Database connection failed: ' . mysqli_connect_error());
        return null;
    }
    return $conn;
}

/**
 * App defaults in one place (no superglobals).
 *
 * @return array{auth_type:string,default_page:string,default_logo:string,js_config:array<string,string>}
 */
function getAppDefaults(): array
{
    return [
        'auth_type' => getenv('AUTH_TYPE') ?: 'sql',
        'default_page' => 'home',
        'default_logo' => '/images/default-logo.svg',
        'js_config' => [
            'recaptcha_key' => getenv('RECAPTCHA_SITE_KEY') ?: '',
        ],
    ];
}

/**
 * Determine whether a Google reCAPTCHA v3 assessment permits a login.
 *
 * @param array<string,mixed> $assessment Google siteverify response
 */
function isValidRecaptchaLoginAssessment(array $assessment): bool
{
    $score = $assessment['score'] ?? null;

    return ($assessment['success'] ?? false) === true
        && ($assessment['action'] ?? null) === 'login'
        && is_numeric($score)
        && (float) $score >= 0.5;
}

/**
 * Read database configuration from environment.
 *
 * @return array{host:string,user:string,password:string,database:string}
 */
function getDbConfig(): array
{
    return [
        'host' => getenv('MYSQL_HOST') ?: 'localhost',
        'database' => getenv('MYSQL_DATABASE') ?: 'postportal',
        'user' => getenv('MYSQL_USER') ?: 'postportal',
        'password' => getenv('MYSQL_PASSWORD') ?: 'postportal',
    ];
}

/**
 * Convenience wrapper to open the default database connection.
 */
function getDefaultDbConnection(): ?\mysqli
{
    $cfg = getDbConfig();
    return getDbConnection($cfg['host'], $cfg['user'], $cfg['password'], $cfg['database']);
}

/**
 * Get canonical application URL.
 * Requires APP_URL environment variable with absolute http/https URL.
 * Validates: no credentials, no query string, no fragment, no control characters.
 * Does not trust request headers.
 * Normalizes to no trailing slash.
 *
 * @return string Canonical URL (e.g., "https://example.com" or "https://example.com:8080/app")
 * @throws RuntimeException If URL is missing, invalid, or malformed
 */
function getCanonicalAppUrl(): string
{
    $url = getenv('APP_URL');
    if ($url === false || $url === '') {
        throw new RuntimeException('APP_URL environment variable is required');
    }

    // Reject control characters (null bytes, newlines, etc.)
    if (preg_match('/[\x00-\x1f\x7f-\x9f]/', $url)) {
        throw new RuntimeException('APP_URL contains invalid control characters');
    }

    // Parse URL
    $parts = parse_url($url);
    if ($parts === false) {
        throw new RuntimeException('APP_URL is not a valid URL');
    }

    // Require scheme
    if (!isset($parts['scheme'])) {
        throw new RuntimeException('APP_URL must include a scheme (http or https)');
    }

    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        throw new RuntimeException('APP_URL scheme must be http or https');
    }

    // Require host
    if (!isset($parts['host']) || $parts['host'] === '') {
        throw new RuntimeException('APP_URL must include a host');
    }

    // Reject credentials in URL
    if (isset($parts['user']) || isset($parts['pass'])) {
        throw new RuntimeException('APP_URL must not contain credentials (user:pass)');
    }

    // Reject query string
    if (isset($parts['query'])) {
        throw new RuntimeException('APP_URL must not contain a query string');
    }

    // Reject fragment
    if (isset($parts['fragment'])) {
        throw new RuntimeException('APP_URL must not contain a fragment');
    }

    // Rebuild URL: scheme://host[:port][/path]
    $canonical = $scheme . '://' . $parts['host'];

    // Add port if present and not default
    if (isset($parts['port'])) {
        $canonical .= ':' . $parts['port'];
    }

    // Add path if present (strip trailing slashes)
    if (isset($parts['path']) && $parts['path'] !== '/' && $parts['path'] !== '') {
        $canonical .= rtrim($parts['path'], '/');
    }

    return $canonical;
}


/**
 * Format a datetime string from the database (assumed UTC) to the configured timezone.
 *
 * @param string|null $datetime The datetime string from the database (Y-m-d H:i:s format)
 * @param string $format The output format (default: 'M j, Y g:i A')
 * @return string|null Formatted datetime string or null if input is null/empty
 */
function formatDateTimeLocal(?string $datetime, string $format = 'M j, Y g:i A'): ?string
{
    if (empty($datetime)) {
        return null;
    }

    try {
        // Database stores in UTC
        $date = new DateTime($datetime, new DateTimeZone('UTC'));

        // Convert to configured timezone (default to UTC if not set)
        $tz = getenv('TZ') ?: 'UTC';
        $date->setTimezone(new DateTimeZone($tz));

        return $date->format($format);
    } catch (Exception $e) {
        error_log('formatDateTimeLocal error: ' . $e->getMessage());
        return $datetime; // Return original on error
    }
}

/**
 * Registers a function to be called when the script execution ends.
 *
 * This function allows you to register a custom shutdown function
 * that will be called when the script execution ends, whether by
 * normal termination or due to a fatal error.
 *
 * @param callable $callback The callback function to be executed on shutdown.
 *                           It can be any valid PHP callable (e.g., a function name,
 *                           an anonymous function, or an array containing an object
 *                           instance and a method name).
 * @param mixed    $parameter Optional. Additional parameters to be passed to the callback function.
 *                           These parameters will be passed to the callback function in the order
 *                           they are specified here.
 * @param mixed    $_         Optional. Additional parameters to be passed to the callback function.
 *                           These parameters will be passed to the callback function in the order
 *                           they are specified here.
 * @return bool Returns true on success, or false on failure.
 */
// Register a custom error handler to catch fatal errors
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && $error['type'] === E_ERROR) {
        // Check if the error is related to memory exhaustion
        if (strpos($error['message'], 'Allowed memory size') !== false) {
            $memoryLimit = ini_get('memory_limit'); // Get the current PHP memory limit
            echo "An error occurred: Memory limit of $memoryLimit was exceeded."; // Handle the memory exhaustion error
        }
    }
});

// ==============================
// Post Portal Helper Functions
// ==============================

/**
 * Purge the nginx FastCGI cache.
 * Called automatically when content is published to ensure visitors see fresh content.
 *
 * @return bool True if purge succeeded or cache not available, false on error
 */
function purgeFastCgiCache(): bool
{
    $cacheDir = '/var/cache/nginx/fastcgi';

    // If cache directory doesn't exist, caching is not enabled
    if (!is_dir($cacheDir)) {
        return true;
    }

    // Try using the purge script if available
    if (is_executable('/docker-scripts/cache-purge.sh')) {
        $output = [];
        $returnCode = 0;
        exec('/docker-scripts/cache-purge.sh 2>&1', $output, $returnCode);
        if ($returnCode === 0) {
            error_log('FastCGI cache purged successfully');
            return true;
        }
        error_log('FastCGI cache purge script failed: ' . implode("\n", $output));
    }

    // Fallback: try direct deletion (requires www-data permissions)
    try {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        error_log('FastCGI cache purged via direct deletion');
        return true;
    } catch (Throwable $e) {
        error_log('FastCGI cache purge failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Sanitize HTML content using a strict DOM allowlist
 * Parses HTML, removes all attributes by default, then restores only safe tag-specific attributes
 * Detects and removes event handlers, executable schemes, dangerous attributes from all tags
 * Output is idempotent: sanitizing sanitized HTML produces the same result
 *
 * @param string $html HTML content to sanitize (can be empty)
 * @return string Sanitized HTML (empty string if input is empty)
 */
function sanitizeHtml(string $html): string
{
    if (trim($html) === '') {
        return '';
    }

    $prevUseInternalErrors = libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');

    // Load HTML with wrapper div to handle fragments
    $wrappedHtml = '<?xml encoding="UTF-8"><div>' . $html . '</div>';
    $dom->loadHTML($wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    // Define allowed elements and their safe attributes
    $allowedElements = [
        'p' => ['class'],
        'br' => [],
        'strong' => ['class'],
        'b' => ['class'],
        'em' => ['class'],
        'i' => ['class'],
        'u' => ['class'],
        'ol' => ['class'],
        'ul' => ['class'],
        'li' => ['class'],
        'blockquote' => ['class'],
        'code' => ['class'],
        'pre' => ['class'],
        'a' => ['href', 'class', 'rel', 'target'],
        'h1' => ['class'],
        'h2' => ['class'],
        'h3' => ['class'],
        'h4' => ['class'],
        'h5' => ['class'],
        'h6' => ['class'],
        'img' => ['src', 'alt', 'class', 'width', 'height'],
        'figure' => ['class'],
        'figcaption' => ['class'],
        'hr' => ['class'],
        'span' => ['class'],
        'div' => ['class'],
    ];

    // Allowed Quill alignment classes
    $allowedClasses = ['ql-align-left', 'ql-align-center', 'ql-align-right', 'ql-align-justify'];

    // Schemes allowed for href/src
    $allowedSchemes = ['http', 'https', 'mailto', 'tel', 'sms'];

    // Helper: Check if an attribute value is safe (no executable schemes or dangerous content)
     $isSafeUrl = function(string $value) use ($allowedSchemes): bool {
         // Decode entities and control characters to detect obfuscated attacks
         $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
         // Remove null bytes and control characters used in obfuscation
         $decoded = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $decoded);
         $trimmed = trim($decoded);

         if (empty($trimmed) || $trimmed[0] === '#') {
             return true; // Fragment or empty
         }

         // Allow single root-relative /path but reject protocol-relative //host/path
         if ($trimmed[0] === '/') {
             // Check for protocol-relative URL (starts with //)
             if (strlen($trimmed) > 1 && $trimmed[1] === '/') {
                 return false; // Protocol-relative URLs not allowed
             }
             return true; // Single-slash root-relative paths are safe
         }

         // Check for dangerous schemes
         if (preg_match('/^(javascript|data|vbscript|file|about):/i', $trimmed)) {
             return false;
         }

         // Check for scheme prefix
         if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $trimmed)) {
             $scheme = strtolower(explode(':', $trimmed)[0]);
             return in_array($scheme, $allowedSchemes, true);
         }

         // No scheme - treat as relative/bare domain
         return true;
     };

    // Walk all elements and enforce allowlist
    $xpath = new DOMXPath($dom);
    $allElements = $xpath->query('//*');

    if ($allElements !== false) {
        $elementsToRemove = [];

        /** @var \DOMElement $element */
        foreach ($allElements as $element) {
            $tag = strtolower($element->nodeName);

            // Remove unknown tags
            if (!isset($allowedElements[$tag])) {
                $elementsToRemove[] = $element;
                continue;
            }

            // Remove all attributes except safe ones for this tag
            $safeAttrs = $allowedElements[$tag];
            $attrsToRemove = [];

            foreach ($element->attributes as $attr) {
                $attrName = strtolower($attr->name);

                // Remove any attribute with "on" prefix (event handlers)
                if (strpos($attrName, 'on') === 0) {
                    $attrsToRemove[] = $attr->name;
                    continue;
                }

                // Remove dangerous attributes
                if (in_array($attrName, ['style', 'srcdoc', 'srcset', 'xmlns', 'xlink', 'svg', 'script'], true)) {
                    $attrsToRemove[] = $attr->name;
                    continue;
                }

                // Keep only safe attributes
                if (!in_array($attrName, $safeAttrs, true)) {
                    $attrsToRemove[] = $attr->name;
                    continue;
                }

                // Validate URL attributes
                if (in_array($attrName, ['href', 'src'], true)) {
                    if (!$isSafeUrl($attr->value)) {
                        $attrsToRemove[] = $attr->name;
                    }
                }

                // Validate classes
                if ($attrName === 'class') {
                    $classes = preg_split('/\s+/', $attr->value, -1, PREG_SPLIT_NO_EMPTY);
                    $safeClasses = array_filter($classes, function($cls) use ($allowedClasses) {
                        return in_array(trim($cls), $allowedClasses);
                    });

                    if (empty($safeClasses)) {
                        $attrsToRemove[] = $attr->name;
                    } else {
                        $element->setAttribute('class', implode(' ', $safeClasses));
                    }
                }
            }

            // Remove unsafe attributes
            foreach ($attrsToRemove as $attrName) {
                $element->removeAttribute($attrName);
            }

             // Link-specific processing
             if ($tag === 'a') {
                 $href = $element->getAttribute('href');
                 if (!$href) {
                     $element->setAttribute('href', '#');
                 } else {
                     // Normalize bare domains to https
                     $hrefTrimmed = trim($href);
                     if ($hrefTrimmed && !preg_match('/^[a-z][a-z0-9+.-]*:/i', $hrefTrimmed) &&
                         !preg_match('/^[#\/]/', $hrefTrimmed) && filter_var($hrefTrimmed, FILTER_VALIDATE_EMAIL)) {
                         $element->setAttribute('href', 'mailto:' . $hrefTrimmed);
                     } elseif ($hrefTrimmed && !preg_match('/^[a-z][a-z0-9+.-]*:/i', $hrefTrimmed) &&
                               !preg_match('/^[#\/]/', $hrefTrimmed) && preg_match('/^(www\.|[a-z0-9.-]+\.[a-z]{2,})/i', $hrefTrimmed)) {
                         $element->setAttribute('href', 'https://' . $hrefTrimmed);
                     }
                 }

                 // Constrain target to allowed values
                 $target = strtolower($element->getAttribute('target'));
                 if ($target && $target !== '_blank' && $target !== '_self') {
                     $element->removeAttribute('target');
                 }

                 // Normalize rel to controlled tokens only
                 $rel = $element->getAttribute('rel');
                 if ($rel) {
                     $relTokens = preg_split('/\s+/', $rel, -1, PREG_SPLIT_NO_EMPTY);
                     $allowedRelTokens = ['noopener', 'noreferrer', 'nofollow', 'external'];
                     $safeRelTokens = array_filter($relTokens, function($token) use ($allowedRelTokens) {
                         return in_array(strtolower($token), $allowedRelTokens, true);
                     });
                     if (empty($safeRelTokens)) {
                         $element->removeAttribute('rel');
                     } else {
                         $element->setAttribute('rel', implode(' ', array_unique($safeRelTokens)));
                     }
                 }

                 // If target=_blank, force noopener noreferrer
                 if ($target === '_blank') {
                     $rel = $element->getAttribute('rel');
                     $rels = $rel ? preg_split('/\s+/', $rel, -1, PREG_SPLIT_NO_EMPTY) : [];
                     $rels = array_unique(array_merge($rels, ['noopener', 'noreferrer']));
                     $element->setAttribute('rel', implode(' ', $rels));
                 }
             }
        }

        // Remove disallowed elements (walk backward to preserve iteration)
        foreach (array_reverse($elementsToRemove) as $element) {
            $element->parentNode->removeChild($element);
        }
    }

    // Extract content from wrapper div
    $body = $dom->getElementsByTagName('div')->item(0);
    $clean = '';
    if ($body) {
        foreach ($body->childNodes as $child) {
            $clean .= $dom->saveHTML($child);
        }
    } else {
        $clean = $dom->saveHTML();
    }

    libxml_clear_errors();
    libxml_use_internal_errors($prevUseInternalErrors);

    return $clean;
}

/**
 * Generate a text excerpt from HTML
 */
function generateExcerpt(string $html, int $maxLength = 250): string
{
    $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
    if (mb_strlen($text, 'UTF-8') <= $maxLength) {
        return $text;
    }
    $cut = mb_substr($text, 0, $maxLength, 'UTF-8');
    // Avoid cutting a word in half
    $spacePos = mb_strrpos($cut, ' ');
    if ($spacePos !== false) {
        $cut = mb_substr($cut, 0, $spacePos);
    }
    return $cut . '…';
}

/**
 * CSRF and session helpers
 */
function ensureSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function generateCsrfToken(): string
{
    ensureSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(string $token): bool
{
    ensureSession();
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
}

/**
 * Posts - CRUD helpers
 */
function createPost(\mysqli $db_conn, array $data): array
{
    try {
        $title = $data['title'] ?? null;
        $body_html = sanitizeHtml($data['body_html'] ?? '');
        $excerpt = $data['excerpt'] ?? generateExcerpt($body_html, 250);
        $hero_media_id = !empty($data['hero_media_id']) ? (int)$data['hero_media_id'] : null;
        $hero_image_height = !empty($data['hero_image_height']) ? (int)$data['hero_image_height'] : 100;
        $hero_crop_overlay = isset($data['hero_crop_overlay']) ? (int)(bool)$data['hero_crop_overlay'] : 0;
        $hero_title_overlay = isset($data['hero_title_overlay']) ? (int)(bool)$data['hero_title_overlay'] : 1;
        $hero_overlay_opacity = isset($data['hero_overlay_opacity']) ? (float)$data['hero_overlay_opacity'] : 0.70;
        $gallery_media_ids = !empty($data['gallery_media_ids']) ? json_encode($data['gallery_media_ids']) : null;
        $status = in_array(($data['status'] ?? 'draft'), ['draft','published']) ? $data['status'] : 'draft';
        $published_at = !empty($data['published_at']) ? $data['published_at'] : null;
        $created_by = $data['created_by_user_id'] ?? ($_SESSION['username'] ?? 'admin');

        // Auto-set published_at when status is 'published' and no published_at is provided
        if ($status === 'published' && is_null($published_at)) {
            $published_at = date('Y-m-d H:i:s');
        }

        $stmt = mysqli_prepare($db_conn, "INSERT INTO posts (title, body_html, excerpt, hero_media_id, hero_image_height, hero_crop_overlay, hero_title_overlay, hero_overlay_opacity, gallery_media_ids, status, published_at, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            throw new \Exception('Failed to prepare statement: ' . mysqli_error($db_conn));
        }
        mysqli_stmt_bind_param($stmt, 'sssiiiidssss', $title, $body_html, $excerpt, $hero_media_id, $hero_image_height, $hero_crop_overlay, $hero_title_overlay, $hero_overlay_opacity, $gallery_media_ids, $status, $published_at, $created_by);
        if (!mysqli_stmt_execute($stmt)) {
            throw new \Exception('Failed to execute statement: ' . mysqli_error($db_conn));
        }
        return ['success' => true, 'id' => mysqli_insert_id($db_conn)];
    } catch (\Throwable $e) {
        error_log('createPost error: ' . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function updatePost(\mysqli $db_conn, int $id, array $data): array
{
    try {
        $id = (int)$id;
        $title = $data['title'] ?? null;
        $body_html = isset($data['body_html']) ? sanitizeHtml($data['body_html']) : null;
        $excerpt = $data['excerpt'] ?? null;
        $hero_media_id = array_key_exists('hero_media_id', $data) ? (is_null($data['hero_media_id']) ? null : (int)$data['hero_media_id']) : null;
        $hero_image_height = array_key_exists('hero_image_height', $data) ? (is_null($data['hero_image_height']) ? null : (int)$data['hero_image_height']) : null;
        $hero_crop_overlay = array_key_exists('hero_crop_overlay', $data) ? (int)(bool)$data['hero_crop_overlay'] : null;
        $hero_title_overlay = array_key_exists('hero_title_overlay', $data) ? (int)(bool)$data['hero_title_overlay'] : null;
        $hero_overlay_opacity = array_key_exists('hero_overlay_opacity', $data) ? (float)$data['hero_overlay_opacity'] : null;
        $gallery_media_ids = array_key_exists('gallery_media_ids', $data) ? json_encode($data['gallery_media_ids']) : null;
        $status = isset($data['status']) && in_array($data['status'], ['draft','published']) ? $data['status'] : null;
        $published_at = $data['published_at'] ?? null;

        // Auto-set published_at when status changes to 'published'
        // Check current status and published_at from database
        // Only auto-set if no published_at is provided by the user
        if ($status === 'published' && is_null($published_at)) {
            $stmt_check = mysqli_prepare($db_conn, "SELECT status, published_at FROM posts WHERE id = ?");
            if (!$stmt_check) {
                throw new \Exception('Failed to prepare check statement: ' . mysqli_error($db_conn));
            }
            mysqli_stmt_bind_param($stmt_check, 'i', $id);
            mysqli_stmt_execute($stmt_check);
            $current = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_check));
            if ($current && is_null($current['published_at'])) {
                $published_at = date('Y-m-d H:i:s');
            }
        }

        // Build dynamic update
        $fields = [];
        $params = [];
        $types = '';

        if (!is_null($title)) { $fields[] = 'title = ?'; $params[] = $title; $types .= 's'; }
        if (!is_null($body_html)) { $fields[] = 'body_html = ?'; $params[] = $body_html; $types .= 's'; }
        if (!is_null($excerpt)) { $fields[] = 'excerpt = ?'; $params[] = $excerpt; $types .= 's'; }
        if (!is_null($hero_media_id) || array_key_exists('hero_media_id', $data)) { $fields[] = 'hero_media_id = ?'; $params[] = $hero_media_id; $types .= 'i'; }
        if (!is_null($hero_image_height) || array_key_exists('hero_image_height', $data)) { $fields[] = 'hero_image_height = ?'; $params[] = $hero_image_height; $types .= 'i'; }
        if (!is_null($hero_crop_overlay) || array_key_exists('hero_crop_overlay', $data)) { $fields[] = 'hero_crop_overlay = ?'; $params[] = $hero_crop_overlay; $types .= 'i'; }
        if (!is_null($hero_title_overlay) || array_key_exists('hero_title_overlay', $data)) { $fields[] = 'hero_title_overlay = ?'; $params[] = $hero_title_overlay; $types .= 'i'; }
        if (!is_null($hero_overlay_opacity) || array_key_exists('hero_overlay_opacity', $data)) { $fields[] = 'hero_overlay_opacity = ?'; $params[] = $hero_overlay_opacity; $types .= 'd'; }
        if (!is_null($gallery_media_ids) || array_key_exists('gallery_media_ids', $data)) { $fields[] = 'gallery_media_ids = ?'; $params[] = $gallery_media_ids; $types .= 's'; }
        if (!is_null($status)) { $fields[] = 'status = ?'; $params[] = $status; $types .= 's'; }
        if (!is_null($published_at)) { $fields[] = 'published_at = ?'; $params[] = $published_at; $types .= 's'; }

        if (empty($fields)) {
            return ['success' => false, 'error' => 'No fields to update'];
        }

        $sql = 'UPDATE posts SET ' . implode(', ', $fields) . ', updated_at = CURRENT_TIMESTAMP WHERE id = ?';
        $types .= 'i';
        $params[] = $id;

        $stmt = mysqli_prepare($db_conn, $sql);
        if (!$stmt) {
            throw new \Exception('Failed to prepare statement: ' . mysqli_error($db_conn));
        }
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        if (!mysqli_stmt_execute($stmt)) {
            throw new \Exception('Failed to execute statement: ' . mysqli_error($db_conn));
        }
        return ['success' => true];
    } catch (\Throwable $e) {
        error_log('updatePost error: ' . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function deletePost(\mysqli $db_conn, int $id): bool
{
    try {
        $id = (int)$id;
        // Soft delete - set deleted_at timestamp instead of actually deleting
        $stmt = mysqli_prepare($db_conn, 'UPDATE posts SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL');
        if (!$stmt) {
            throw new \Exception('Failed to prepare statement: ' . mysqli_error($db_conn));
        }
        mysqli_stmt_bind_param($stmt, 'i', $id);
        return mysqli_stmt_execute($stmt);
    } catch (\Throwable $e) {
        error_log('deletePost error: ' . $e->getMessage());
        return false;
    }
}

function publishDraft(\mysqli $db_conn, int $id): array
{
    try {
        // Copy all draft fields to published fields for posts
        // First, check which draft fields have been explicitly set (are not NULL)
        // For nullable fields like hero_media_id, we need to check if the draft differs from published
        $id = (int)$id;

        // Get current draft state to determine what to update
        $stmt_check = mysqli_prepare($db_conn, "SELECT
            title_draft, body_html_draft, hero_media_id_draft, hero_image_height_draft,
            hero_crop_overlay_draft, hero_title_overlay_draft, hero_overlay_opacity_draft,
            gallery_media_ids_draft,
            title, body_html, hero_media_id, hero_image_height,
            hero_crop_overlay, hero_title_overlay, hero_overlay_opacity, gallery_media_ids
            FROM posts WHERE id = ? AND deleted_at IS NULL");
        if (!$stmt_check) {
            throw new \Exception('Failed to prepare check statement: ' . mysqli_error($db_conn));
        }
        mysqli_stmt_bind_param($stmt_check, 'i', $id);
        mysqli_stmt_execute($stmt_check);
        $post = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_check));

        if (!$post) {
            return ['success' => false, 'error' => 'Post not found'];
        }

        // Build dynamic update based on which draft fields exist
        $updates = [];
        $params = [];
        $types = '';

        // For each field, if draft is set (not null), use it; otherwise keep current
        // Special handling: hero_media_id_draft can be explicitly set to NULL to remove the image
        // We detect this by checking if ANY draft field has been set, meaning the user has edited the post
        $draftWasEdited = $post['title_draft'] !== null ||
                          $post['body_html_draft'] !== null ||
                          $post['hero_image_height_draft'] !== null ||
                          $post['hero_crop_overlay_draft'] !== null;

        if ($post['title_draft'] !== null) {
            $updates[] = 'title = ?';
            $params[] = $post['title_draft'];
            $types .= 's';
        }

        if ($post['body_html_draft'] !== null) {
            $updates[] = 'body_html = ?';
            $params[] = $post['body_html_draft'];
            $types .= 's';
        }

        // hero_media_id: if draft was edited, copy draft value (even if NULL = remove image)
        if ($draftWasEdited) {
            $updates[] = 'hero_media_id = ?';
            $params[] = $post['hero_media_id_draft']; // Can be NULL
            $types .= 'i';
        }

        if ($post['hero_image_height_draft'] !== null) {
            $updates[] = 'hero_image_height = ?';
            $params[] = $post['hero_image_height_draft'];
            $types .= 'i';
        }

        if ($post['hero_crop_overlay_draft'] !== null) {
            $updates[] = 'hero_crop_overlay = ?';
            $params[] = $post['hero_crop_overlay_draft'];
            $types .= 'i';
        }

        if ($post['hero_title_overlay_draft'] !== null) {
            $updates[] = 'hero_title_overlay = ?';
            $params[] = $post['hero_title_overlay_draft'];
            $types .= 'i';
        }

        if ($post['hero_overlay_opacity_draft'] !== null) {
            $updates[] = 'hero_overlay_opacity = ?';
            $params[] = $post['hero_overlay_opacity_draft'];
            $types .= 'd';
        }

        if ($post['gallery_media_ids_draft'] !== null) {
            $updates[] = 'gallery_media_ids = ?';
            $params[] = $post['gallery_media_ids_draft'];
            $types .= 's';
        }

        // Always update status and timestamps
        $updates[] = "status = 'published'";
        $updates[] = 'published_at = COALESCE(published_at, CURRENT_TIMESTAMP)';
        $updates[] = 'updated_at = CURRENT_TIMESTAMP';

        $sql = 'UPDATE posts SET ' . implode(', ', $updates) . ' WHERE id = ? AND deleted_at IS NULL';
        $types .= 'i';
        $params[] = $id;

        $stmt = mysqli_prepare($db_conn, $sql);
        if (!$stmt) {
            throw new \Exception('Failed to prepare statement: ' . mysqli_error($db_conn));
        }
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        if (!mysqli_stmt_execute($stmt)) {
            throw new \Exception('Failed to execute statement: ' . mysqli_error($db_conn));
        }

        // Refresh excerpt based on the now-published body_html to avoid stale excerpts
        // This ensures email notifications and list views use up-to-date summaries
        $stmt_body = mysqli_prepare($db_conn, "SELECT body_html FROM posts WHERE id = ? LIMIT 1");
        if ($stmt_body) {
            mysqli_stmt_bind_param($stmt_body, 'i', $id);
            mysqli_stmt_execute($stmt_body);
            $res = mysqli_stmt_get_result($stmt_body);
            if ($res) {
                $row = mysqli_fetch_assoc($res);
                if ($row && isset($row['body_html'])) {
                    $newExcerpt = generateExcerpt($row['body_html'], 250);
                    $stmt2 = mysqli_prepare($db_conn, 'UPDATE posts SET excerpt = ? WHERE id = ?');
                    if ($stmt2) {
                        mysqli_stmt_bind_param($stmt2, 'si', $newExcerpt, $id);
                        // If this update fails, don't fail the publish action; log and continue
                        if (!mysqli_stmt_execute($stmt2)) {
                            error_log('Failed to refresh excerpt on publish for post ' . $id . ': ' . mysqli_error($db_conn));
                        }
                    }
                }
            }
        }

        // Clear draft fields after successful publish
        $clearDraftSql = "UPDATE posts SET
            title_draft = NULL,
            body_html_draft = NULL,
            hero_media_id_draft = NULL,
            hero_image_height_draft = NULL,
            hero_crop_overlay_draft = NULL,
            hero_title_overlay_draft = NULL,
            hero_overlay_opacity_draft = NULL,
            gallery_media_ids_draft = NULL
            WHERE id = ?";
        $stmt_clear = mysqli_prepare($db_conn, $clearDraftSql);
        if ($stmt_clear) {
            mysqli_stmt_bind_param($stmt_clear, 'i', $id);
            mysqli_stmt_execute($stmt_clear);
        }

        // Purge FastCGI cache so visitors see the new content immediately
        purgeFastCgiCache();

        return ['success' => true];
    } catch (\Throwable $e) {
        error_log('publishDraft error: ' . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Publish draft settings to production
 * Uses COALESCE to preserve empty string values from draft
 * Empty strings are NOT NULL, so they will be honored if explicitly set
 * This allows users to erase content from about/donate sections if desired
 *
 * @param mysqli $db_conn Database connection
 * @return array Result with success status
 */
function publishSettingsDraft(\mysqli $db_conn): array
{
    try {
        // Copy all draft fields to published fields for settings
        $sql = "UPDATE settings SET
            hero_html = COALESCE(hero_html_draft, hero_html),
            site_bio_html = COALESCE(site_bio_html_draft, site_bio_html),
            donate_text_html = COALESCE(donate_text_html_draft, donate_text_html),
            donation_instructions_html = COALESCE(donation_instructions_html_draft, donation_instructions_html),
            footer_column1_html = COALESCE(footer_column1_html_draft, footer_column1_html),
            footer_column2_html = COALESCE(footer_column2_html_draft, footer_column2_html),
            mailing_list_html = COALESCE(mailing_list_html_draft, mailing_list_html),
            updated_at = CURRENT_TIMESTAMP
        WHERE id = 1";

        $stmt = mysqli_prepare($db_conn, $sql);
        if (!$stmt) {
            throw new \Exception('Failed to prepare statement: ' . mysqli_error($db_conn));
        }

        if (!mysqli_stmt_execute($stmt)) {
            throw new \Exception('Failed to execute statement: ' . mysqli_error($db_conn));
        }

        // Purge FastCGI cache so visitors see updated content immediately
        purgeFastCgiCache();

        return ['success' => true];
    } catch (\Throwable $e) {
        error_log('publishSettingsDraft error: ' . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Decode HTML entities in post data for proper UTF-8 display
 * This ensures that content stored with HTML entities is properly decoded
 * before being displayed to prevent character encoding issues
 *
 * @param array|null $post Post data array
 * @return array|null Post data with decoded HTML entities
 */
function decodePostHtmlEntities(?array $post): ?array {
    if (!$post) {
        return null;
    }

    // Fields that may contain HTML entities that need decoding
    $fieldsToDecodec = ['title', 'body_html', 'excerpt'];

    foreach ($fieldsToDecodec as $field) {
        if (isset($post[$field]) && $post[$field] !== null) {
            $post[$field] = html_entity_decode($post[$field], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }

    return $post;
}

function getPost(\mysqli $db_conn, int $id): ?array
{
    $id = (int)$id;
    $sql = "SELECT p.*,
                   u.first AS author_first, u.last AS author_last, u.username AS author_username
            FROM posts p
            LEFT JOIN users u ON p.created_by_user_id = u.username
            WHERE p.id = ? AND p.deleted_at IS NULL LIMIT 1";
    $stmt = mysqli_prepare($db_conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $post = $result ? mysqli_fetch_assoc($result) : null;
    return decodePostHtmlEntities($post);
}

function getPublishedPosts(\mysqli $db_conn, int $limit = 10, int $offset = 0): array
{
    $limit = (int)$limit; $offset = (int)$offset;
    $sql = "SELECT p.*, m.variants_json AS hero_variants,
                   u.first AS author_first, u.last AS author_last, u.username AS author_username
            FROM posts p
            LEFT JOIN media m ON p.hero_media_id = m.id
            LEFT JOIN users u ON p.created_by_user_id = u.username
            WHERE p.status = 'published' AND p.deleted_at IS NULL
            ORDER BY COALESCE(p.published_at, p.created_at) DESC
            LIMIT ? OFFSET ?";
    $stmt = mysqli_prepare($db_conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ii', $limit, $offset);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $rows[] = decodePostHtmlEntities($r);
        }
    }
    return $rows;
}

/**
 * Media helpers
 */
function saveMediaRecord(\mysqli $db_conn, array $data): array
{
    $stmt = mysqli_prepare($db_conn, "INSERT INTO media (filename, original_filename, mime_type, size_bytes, width, height, alt_text, storage_path, variants_json, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param(
        $stmt,
        'sssiiissss',
        $data['filename'],
        $data['original_filename'],
        $data['mime_type'],
        $data['size_bytes'],
        $data['width'],
        $data['height'],
        $data['alt_text'],
        $data['storage_path'],
        $data['variants_json'],
        $data['created_by_user_id']
    );
    if (!mysqli_stmt_execute($stmt)) {
        return ['success' => false, 'error' => mysqli_error($db_conn)];
    }

    return ['success' => true, 'id' => mysqli_insert_id($db_conn)];
}

function getMedia(\mysqli $db_conn, int $id): ?array
{
    $id = (int)$id;
    $stmt = mysqli_prepare($db_conn, "SELECT * FROM media WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    return $res ? mysqli_fetch_assoc($res) : null;
}

function getAllMedia(\mysqli $db_conn, int $limit = 50, int $offset = 0, ?string $search = null): array
{
    $limit = (int)$limit; $offset = (int)$offset;

    if ($search) {
        $searchPattern = "%{$search}%";
        $sql = "SELECT * FROM media WHERE original_filename LIKE ? OR alt_text LIKE ? ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $stmt = mysqli_prepare($db_conn, $sql);
        mysqli_stmt_bind_param($stmt, 'ssii', $searchPattern, $searchPattern, $limit, $offset);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
    } else {
        $sql = "SELECT * FROM media ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $stmt = mysqli_prepare($db_conn, $sql);
        mysqli_stmt_bind_param($stmt, 'ii', $limit, $offset);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
    }

    $rows = [];
    if ($res) { while ($r = mysqli_fetch_assoc($res)) { $rows[] = $r; } }
    return $rows;
}

/**
 * Settings helpers
 */
function getSettings(\mysqli $db_conn): ?array
{
    $sql = 'SELECT * FROM settings WHERE id = ?';
    $stmt = mysqli_prepare($db_conn, $sql);

    if (!$stmt) {
        error_log('getSettings: failed to prepare statement: ' . mysqli_error($db_conn));
        return null;
    }

    $settingsId = 1;
    mysqli_stmt_bind_param($stmt, 'i', $settingsId);

    if (!mysqli_stmt_execute($stmt)) {
        error_log('getSettings: failed to execute statement: ' . mysqli_error($db_conn));
        return null;
    }

    $res = mysqli_stmt_get_result($stmt);
    $settings = $res ? mysqli_fetch_assoc($res) : null;

    // Decrypt SMTP password if it exists
    if ($settings && !empty($settings['smtp_password'])) {
        $settings['smtp_password'] = decryptSmtpPassword($settings['smtp_password']);
    }

    // Normalize donation_link to include protocol if missing
    if ($settings && !empty($settings['donation_link'])) {
        $link = $settings['donation_link'];
        if (!preg_match('#^https?://#i', $link)) {
            $settings['donation_link'] = 'https://' . $link;
        }
    }

    return $settings;
}

/**
 * Get logo URL (with retina support via srcset)
 * Returns array with logo_url and logo_srcset_png
 */
function getLogoUrls(\mysqli $db_conn, string|int|null $logo_media_id = null, ?string $defaultLogo = null): array
{
    $default_logo = $defaultLogo ?? '/images/default-logo.svg';

    if (!$logo_media_id) {
        // Return default logo from config
        return [
            'logo_url' => $default_logo,
            'logo_srcset_png' => '',
            'logo_srcset_webp' => ''
        ];
    }

    $media = getMedia($db_conn, (int)$logo_media_id);
    if (!$media || empty($media['variants_json'])) {
        // Return default logo from config
        return [
            'logo_url' => $default_logo,
            'logo_srcset_png' => '',
            'logo_srcset_webp' => ''
        ];
    }

    require_once(__DIR__ . '/lib/MediaProcessor.php');
    $variants = json_decode($media['variants_json'], true);

    // Find the 200w PNG variant for default
    $logo200 = null;
    foreach ($variants as $v) {
        if ($v['width'] == 200 && $v['format'] == 'png') {
            $logo200 = $v;
            break;
        }
    }

    $logo_url = $logo200 ? ('/' . $logo200['path']) : $default_logo;
    $logo_srcset_png = MediaProcessor::generateSrcset($variants, 'png');
    $logo_srcset_webp = MediaProcessor::generateSrcset($variants, 'webp');

    return [
        'logo_url' => $logo_url,
        'logo_srcset_png' => $logo_srcset_png,
        'logo_srcset_webp' => $logo_srcset_webp
    ];
}

/**
 * Get favicon URLs for various sizes
 * Returns array with favicon URLs for different sizes
 */
function getFaviconUrls(\mysqli $db_conn, string|int|null $favicon_media_id = null): array
{
    if (!$favicon_media_id) {
        // Return default favicon
        return [
            'favicon_ico' => '/images/favicon.svg',
            'favicon_svg' => '/images/favicon.svg',
            'favicon_32' => '/images/favicon.svg',
            'favicon_16' => '/images/favicon.svg',
            'favicon_192' => '/images/favicon.svg',
            'favicon_512' => '/images/favicon.svg'
        ];
    }

    $media = getMedia($db_conn, (int)$favicon_media_id);
    if (!$media || empty($media['variants_json'])) {
        return [
            'favicon_ico' => '/images/favicon.svg',
            'favicon_svg' => '/images/favicon.svg',
            'favicon_32' => '/images/favicon.svg',
            'favicon_16' => '/images/favicon.svg',
            'favicon_192' => '/images/favicon.svg',
            'favicon_512' => '/images/favicon.svg'
        ];
    }

    $variants = json_decode($media['variants_json'], true);

    // Initialize with defaults
    $result = [
        'favicon_ico' => null,
        'favicon_svg' => null,
        'favicon_16' => null,
        'favicon_32' => null,
        'favicon_192' => null,
        'favicon_512' => null
    ];

    // Find .ico file
    foreach ($variants as $v) {
        if (isset($v['format']) && $v['format'] == 'ico') {
            $result['favicon_ico'] = '/' . $v['path'];
            break;
        }
    }

    // Find PNG sizes
    $sizes = [16, 32, 192, 512];
    foreach ($sizes as $size) {
        foreach ($variants as $v) {
            if (isset($v['format']) && $v['format'] == 'png' && isset($v['size']) && $v['size'] == $size) {
                $result['favicon_' . $size] = '/' . $v['path'];
                break;
            }
        }
    }

    // Use 32x32 PNG as .ico fallback if .ico wasn't generated
    if (!$result['favicon_ico'] && $result['favicon_32']) {
        $result['favicon_ico'] = $result['favicon_32'];
    }

    return $result;
}

/**
 * Encrypt SMTP password using AES-256-CBC
 *
 * @param string $password Plain text password to encrypt
 * @return string Encrypted password (base64 encoded with IV) or empty string
 */
/**
 * Decrypt and validate APP_ENCRYPTION_KEY from environment.
 * Must be exactly 64 hexadecimal characters (32 bytes when decoded).
 *
 * @return string 32 raw bytes, or empty string if missing/invalid
 */
function getAppEncryptionKeyBytes(): string
{
    $keyHex = getenv('APP_ENCRYPTION_KEY');
    if ($keyHex === false || $keyHex === '') {
        return '';
    }

    // Must be exactly 64 hex characters
    if (!ctype_xdigit($keyHex) || strlen($keyHex) !== 64) {
        error_log('getAppEncryptionKeyBytes: APP_ENCRYPTION_KEY must be exactly 64 hexadecimal characters');
        return '';
    }

    return hex2bin($keyHex);
}

/**
 * Encrypt SMTP password using v2 AES-256-GCM envelope.
 * Requires independent APP_ENCRYPTION_KEY (64 hex chars = 32 bytes).
 * Uses random 12-byte nonce and 16-byte authentication tag.
 *
 * @param string $password SMTP password to encrypt
 * @return string Base64-encoded v2 envelope or empty string on failure
 */
function encryptSmtpPassword(string $password): string
{
    if ($password === '') {
        return '';
    }

    $appKey = getAppEncryptionKeyBytes();
    if ($appKey === '') {
        error_log('encryptSmtpPassword: Missing or invalid APP_ENCRYPTION_KEY');
        return '';
    }

    // v2 format: 1 byte version + 12 bytes nonce + ciphertext + 16 bytes auth tag
    try {
        $nonce = random_bytes(12);
    } catch (Throwable $e) {
        error_log('encryptSmtpPassword: Failed to generate nonce');
        return '';
    }

    $tag = '';  // Will be filled by openssl_encrypt
    $encrypted = openssl_encrypt(
        $password,
        'aes-256-gcm',
        $appKey,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag
    );

    if ($encrypted === false) {
        error_log('encryptSmtpPassword: Encryption failed');
        return '';
    }

    // v2 envelope: version (1) + nonce (12) + ciphertext + tag (16)
    $envelope = chr(2) . $nonce . $encrypted . $tag;
    return base64_encode($envelope);
}

/**
 * Decrypt SMTP password using v2 AES-256-GCM envelope.
 * Rejects legacy formats and malformed envelopes.
 *
 * @param string $encryptedPassword Base64-encoded v2 envelope
 * @return string Decrypted password or empty string on failure
 */
function decryptSmtpPassword(string $encryptedPassword): string
{
    if ($encryptedPassword === '') {
        return '';
    }

    try {
        $appKey = getAppEncryptionKeyBytes();
        if ($appKey === '') {
            error_log('decryptSmtpPassword: Missing or invalid APP_ENCRYPTION_KEY');
            return '';
        }

        $envelope = base64_decode($encryptedPassword, true);
        if ($envelope === false || strlen($envelope) < 29) {  // min: 1 + 12 + 0 + 16
            error_log('decryptSmtpPassword: Invalid envelope - too short or decode failed');
            return '';
        }

        // Parse v2 envelope: version (1) + nonce (12) + ciphertext + tag (16)
        $version = ord($envelope[0]);
        if ($version !== 2) {
            error_log('decryptSmtpPassword: Invalid envelope version - only v2 accepted');
            return '';
        }

        $nonce = substr($envelope, 1, 12);
        $tag = substr($envelope, -16);
        $ciphertext = substr($envelope, 13, -16);

        if (strlen($nonce) !== 12 || strlen($tag) !== 16) {
            error_log('decryptSmtpPassword: Invalid envelope structure');
            return '';
        }

        $decrypted = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $appKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($decrypted === false) {
            error_log('decryptSmtpPassword: Decryption failed - authentication failed or wrong key');
            return '';
        }

        return $decrypted;
    } catch (Exception $e) {
        error_log('decryptSmtpPassword: Exception - ' . $e->getMessage());
        return '';
    }
}

function updateSettings(\mysqli $db_conn, array $data): array
{
    // In demo mode, prevent changing the donation link
    $demoMode = filter_var(getenv('DEMO_MODE') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    if ($demoMode && array_key_exists('donation_link', $data)) {
        unset($data['donation_link']);
    }

    $fields = ['site_title','hero_html','hero_media_id','site_bio_html','donation_settings_json','timezone','cta_text','cta_url','donate_text_html','donation_method','donation_link','donation_qr_media_id','donation_instructions_html','hero_overlay_opacity','hero_overlay_color','show_hero','show_about','show_donation','show_mailing_list','newsletter_position','newsletter_position_scope','show_view_counts','show_impression_counts','ignore_admin_tracking','notify_subscribers_on_post','email_include_post_body','show_donate_button','ai_system_prompt','hero_height','show_footer','footer_layout','footer_media_id','footer_height','footer_overlay_opacity','footer_overlay_color','footer_column1_html','footer_column2_html','mailing_list_html','smtp_rate_limit','smtp_rate_period','smtp_batch_delay','smtp_host','smtp_port','smtp_secure','smtp_auth','smtp_username','smtp_password','smtp_from_email','smtp_from_name','show_logo'];
    $sets = [];
    $params = [];
    $types = '';

    foreach ($fields as $key) {
        if (array_key_exists($key, $data)) {
            $sets[] = "$key = ?";
            // Encrypt SMTP password before saving
            if ($key === 'smtp_password') {
                $params[] = encryptSmtpPassword($data[$key]);
            }
            // Sanitize HTML fields
            elseif ($key === 'hero_html' || $key === 'site_bio_html' || $key === 'donate_text_html' || $key === 'donation_instructions_html' || $key === 'footer_column1_html' || $key === 'footer_column2_html' || $key === 'mailing_list_html') {
                $params[] = sanitizeHtml($data[$key]);
            } else {
                $params[] = $data[$key];
            }
            // Set parameter types
            if ($key === 'hero_media_id' || $key === 'show_hero' || $key === 'show_about' || $key === 'show_donation' || $key === 'show_mailing_list' || $key === 'show_view_counts' || $key === 'show_impression_counts' || $key === 'ignore_admin_tracking' || $key === 'notify_subscribers_on_post' || $key === 'email_include_post_body' || $key === 'show_donate_button' || $key === 'donation_qr_media_id' || $key === 'hero_height' || $key === 'show_footer' || $key === 'footer_media_id' || $key === 'footer_height' || $key === 'smtp_rate_limit' || $key === 'smtp_rate_period' || $key === 'smtp_port' || $key === 'smtp_auth' || $key === 'show_logo') { $types .= 'i'; }
            elseif ($key === 'hero_overlay_opacity' || $key === 'footer_overlay_opacity' || $key === 'smtp_batch_delay') { $types .= 'd'; }
            else { $types .= 's'; }
        }
    }

    if (empty($sets)) { return ['success' => false, 'error' => 'No fields to update']; }

    $sql = 'UPDATE settings SET ' . implode(', ', $sets) . ', updated_at = CURRENT_TIMESTAMP WHERE id = 1';
    $stmt = mysqli_prepare($db_conn, $sql);
    if (!$stmt) {
        return ['success' => false, 'error' => mysqli_error($db_conn)];
    }

    mysqli_stmt_bind_param($stmt, $types, ...$params);

    if (!mysqli_stmt_execute($stmt)) {
        return ['success' => false, 'error' => mysqli_error($db_conn)];
    }

    return ['success' => true];
}

/**
 * Newsletter helpers
 */
function getActiveSubscriberCount(\mysqli $db_conn): int
{
    $sql = 'SELECT COUNT(*) as count FROM newsletter_subscribers WHERE is_active = ?';
    $stmt = mysqli_prepare($db_conn, $sql);

    if (!$stmt) {
        error_log('getActiveSubscriberCount: failed to prepare statement: ' . mysqli_error($db_conn));
        return 0;
    }

    $isActive = 1;
    mysqli_stmt_bind_param($stmt, 'i', $isActive);

    if (!mysqli_stmt_execute($stmt)) {
        error_log('getActiveSubscriberCount: failed to execute statement: ' . mysqli_error($db_conn));
        return 0;
    }

    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        return isset($row['count']) ? (int)$row['count'] : 0;
    }

    return 0;
}

function getTotalSubscriberCount(\mysqli $db_conn): int
{
    $sql = 'SELECT COUNT(*) as count FROM newsletter_subscribers';
    $stmt = mysqli_prepare($db_conn, $sql);

    if (!$stmt) {
        error_log('getTotalSubscriberCount: failed to prepare statement: ' . mysqli_error($db_conn));
        return 0;
    }

    if (!mysqli_stmt_execute($stmt)) {
        error_log('getTotalSubscriberCount: failed to execute statement: ' . mysqli_error($db_conn));
        return 0;
    }

    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        return isset($row['count']) ? (int)$row['count'] : 0;
    }

    return 0;
}

/**
 * Get UNSUBSCRIBE_SECRET from environment.
 * Must be at least 32 bytes/characters.
 *
 * @return string The secret, or empty string if missing/too short
 */
function getUnsubscribeSecret(): string
{
    $secret = getenv('UNSUBSCRIBE_SECRET');
    if ($secret === false || $secret === '') {
        return '';
    }

    if (strlen($secret) < 32) {
        error_log('getUnsubscribeSecret: UNSUBSCRIBE_SECRET must be at least 32 bytes');
        return '';
    }

    return $secret;
}

/**
 * Generate a v2 unsubscribe token for an email address.
 * Requires independent UNSUBSCRIBE_SECRET (at least 32 bytes).
 * Uses constant-time HMAC-SHA256 with purpose binding.
 *
 * @param string $email The email address to generate a token for
 * @return string URL-safe base64 encoded v2 token
 */
function generateUnsubscribeToken(string $email): string
{
    $secret = getUnsubscribeSecret();
    if ($secret === '') {
        error_log('generateUnsubscribeToken: Missing or invalid UNSUBSCRIBE_SECRET');
        return '';
    }

    // v2 payload: version + purpose + email (no timestamp/expiration)
    $payload = json_encode([
        'v' => 2,
        'purpose' => 'unsubscribe',
        'email' => $email
    ]);

    // Generate HMAC-SHA256 signature with constant-time comparison in validation
    $signature = hash_hmac('sha256', $payload, $secret);

    // Combine payload and signature
    $token = base64_encode($payload . '|' . $signature);

    // Make URL-safe
    return strtr($token, '+/', '-_');
}

/**
 * Validate and decode a v2 unsubscribe token.
 * Rejects legacy formats, wrong purpose, and all tampering.
 * Requires independent UNSUBSCRIBE_SECRET (at least 32 bytes).
 *
 * @param string $token The token to validate
 * @return string|false Email address if valid, false otherwise
 */
function validateUnsubscribeToken(string $token): string|false
{
    try {
        $secret = getUnsubscribeSecret();
        if ($secret === '') {
            error_log('validateUnsubscribeToken: Missing or invalid UNSUBSCRIBE_SECRET');
            return false;
        }

        // Canonicalize URL-safe base64: convert - and _ back to + and /
        $canonical = strtr($token, '-_', '+/');

        // Verify canonical form (reject non-standard encodings)
        $reencoded = strtr(base64_encode(base64_decode($canonical, true)), '+/', '-_');
        if ($canonical !== $reencoded) {
            return false;
        }

        // Decode
        $decoded = base64_decode($canonical, true);
        if ($decoded === false) {
            return false;
        }

        // Split payload and signature
        $parts = explode('|', $decoded);
        if (count($parts) !== 2) {
            return false;
        }

        list($payload, $signature) = $parts;

        // Verify signature with constant-time comparison
        $expectedSignature = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expectedSignature, $signature)) {
            return false;
        }

        // Decode payload
        $data = json_decode($payload, true);
        if (!$data || !is_array($data)) {
            return false;
        }

        // Enforce v2 version
        if (!isset($data['v']) || $data['v'] !== 2) {
            return false;
        }

        // Enforce unsubscribe purpose
        if (!isset($data['purpose']) || $data['purpose'] !== 'unsubscribe') {
            return false;
        }

        // Extract email and validate it's a string
        if (!isset($data['email']) || !is_string($data['email'])) {
            return false;
        }

        $email = $data['email'];

        // Validate email format (must be valid)
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return $email;

    } catch (Exception $e) {
        error_log('validateUnsubscribeToken: Exception - ' . $e->getMessage());
        return false;
    }
}

/**
 * SMTP configuration and log path constants
 */
const SMTP_LOG_PATH = '/var/log/postportal/smtp.log';

/**
 * Validate SMTP security policy: authentication requires encryption (TLS/SMTPS)
 *
 * @param bool $smtpAuth Whether SMTP authentication is enabled
 * @param string $smtpSecure Encryption mode: exactly 'tls' or 'ssl' for authenticated connections
 * @return bool True if policy is satisfied, false if authenticated plaintext is attempted
 */
function validateSmtpSecurityPolicy(bool $smtpAuth, string $smtpSecure): bool
{
    // Unauthenticated plaintext is allowed
    if (!$smtpAuth) {
        return true;
    }

    // Authenticated connections MUST use encrypted wire: exactly 'tls' or 'ssl'
    return $smtpSecure === 'tls' || $smtpSecure === 'ssl';
}

/**
 * Configure strict PHPMailer SMTP transport security and time limits.
 *
 * @throws \InvalidArgumentException If the timeout is not positive
 */
function configureSmtpTransportSecurity(\PHPMailer\PHPMailer\PHPMailer $mail, int $timeoutSeconds): void
{
    if ($timeoutSeconds <= 0) {
        throw new \InvalidArgumentException('SMTP timeout must be positive');
    }

    $mail->Timeout = $timeoutSeconds;
    $smtp = $mail->getSMTPInstance();
    $smtp->Timeout = $timeoutSeconds;
    $smtp->Timelimit = $timeoutSeconds;

    $smtpOptions = $mail->SMTPOptions;
    $sslOptions = $smtpOptions['ssl'] ?? [];
    $sslOptions['verify_peer'] = true;
    $sslOptions['verify_peer_name'] = true;
    $sslOptions['allow_self_signed'] = false;
    $smtpOptions['ssl'] = $sslOptions;
    $mail->SMTPOptions = $smtpOptions;
}

/**
 * Get SMTP log file path for owned diagnostics
 *
 * @return string Canonical path: /var/log/postportal/smtp.log
 */
function getSmtpLogPath(): string
{
    return SMTP_LOG_PATH;
}

/**
 * Redact sensitive SMTP diagnostics for logging
 *
 * Removes: credentials (username/password), full email addresses, auth details
 * Keeps: SMTP command structure, connection status, port/host
 *
 * @param string $diagnostic SMTP diagnostic string
 * @return string Redacted string safe for logging
 */
function redactSmtpDiagnostics(string $diagnostic): string
{
    // SMTP AUTH commands and their inline payloads are always sensitive.
    $redacted = preg_replace('/\bAUTH\s+(?:LOGIN|PLAIN|CRAM-MD5)\b[^\r\n]*/i', 'AUTH *** REDACTED ***', $diagnostic) ?? $diagnostic;

    // Base64 payloads can contain SMTP credentials or encoded message data.
    $redacted = preg_replace('/(?<![A-Za-z0-9+\/])[A-Za-z0-9+\/]{16,}={0,2}(?![A-Za-z0-9+\/])/','*** BASE64 REDACTED ***', $redacted) ?? $redacted;

    // Redact email addresses (simplistic but catches most cases)
    $redacted = preg_replace(
        '/(?:[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/i',
        '***@***.***',
        $redacted
    ) ?? $redacted;

    // Redact secrets commonly surfaced in SMTP and HTTP diagnostics.
    $redacted = preg_replace('/\b(password|passwd|pwd|activation(?:[_-]?token)?|unsubscribe(?:[_-]?token)?|token)\b\s*[:=]\s*[^\s&#,;]+/i', '$1=*** REDACTED ***', $redacted) ?? $redacted;
    $redacted = preg_replace('/\bauthorization\s*:\s*[^\r\n]+/i', 'Authorization: *** REDACTED ***', $redacted) ?? $redacted;
    $redacted = preg_replace('/\bbearer\s+[^\s,;]+/i', 'Bearer *** REDACTED ***', $redacted) ?? $redacted;

    // Redact RCPT TO addresses if full email remains
    $redacted = preg_replace(
        '/(RCPT TO:<)[^>]+(>)/i',
        '${1}***@***${2}',
        $redacted
    ) ?? $redacted;

    return $redacted;
}

/**
 * Build notification URL with canonical app URL
 *
 * @param int $postId Post ID
 * @return string Full URL to post or null on error
 */
function buildNotificationPostUrl(int $postId): ?string
{
    try {
        $baseUrl = getCanonicalAppUrl();
        return "{$baseUrl}/?page=home&post_id={$postId}";
    } catch (\RuntimeException $e) {
        error_log('Failed to build notification URL: ' . $e->getMessage());
        return null;
    }
}

/**
 * Check if email configuration is valid for sending notifications
 * Used to pre-validate before publishing when user wants to send emails
 *
 * @param mysqli $db_conn Database connection
 * @param bool $testConnection Whether to actually test the SMTP connection
 * @return array ['canSend' => bool, 'error' => string|null, 'actionRequired' => string|null, 'actionLabel' => string|null, 'subscriberCount' => int]
 */
function checkEmailConfiguration(\mysqli $db_conn, bool $testConnection = true): array
{
    $settings = getSettings($db_conn);

    // Get subscriber count regardless of config status (for UI display)
    $subscriberCount = getActiveSubscriberCount($db_conn);

    if (!$settings || !$settings['notify_subscribers_on_post']) {
        return [
            'canSend' => false,
            'error' => 'Email notifications disabled in settings.',
            'actionRequired' => 'enable_notifications',
            'actionLabel' => 'Enable Email Notifications',
            'subscriberCount' => $subscriberCount
        ];
    }

    $smtp_host = $settings['smtp_host'] ?? null;
    $smtp_from_email = $settings['smtp_from_email'] ?? null;

    $missingFields = [];
    if (!$smtp_host) {
        $missingFields[] = 'SMTP Host';
    }
    if (!$smtp_from_email) {
        $missingFields[] = 'From Email';
    }

    if (!empty($missingFields)) {
        return [
            'canSend' => false,
            'error' => 'SMTP configuration incomplete. Missing: ' . implode(', ', $missingFields),
            'actionRequired' => 'configure_smtp',
            'actionLabel' => 'Configure Email Settings',
            'subscriberCount' => $subscriberCount
        ];
    }

    // Enforce SMTP security policy: extract and validate immediately after field validation
    $smtp_secure = $settings['smtp_secure'] ?? 'none';
    // Convert 'none' to empty string for policy check (PHPMailer convention)
    $smtp_secure_normalized = ($smtp_secure === 'none') ? '' : $smtp_secure;
    $smtp_auth = isset($settings['smtp_auth']) ? (bool)$settings['smtp_auth'] : true;

    // Reject authenticated plaintext connections (no encryption) - applies regardless of testConnection
    if (!validateSmtpSecurityPolicy($smtp_auth, $smtp_secure_normalized)) {
        error_log('SMTP security policy violation: authentication attempted without TLS/SMTPS encryption');
        return [
            'canSend' => false,
            'error' => 'SMTP configuration error. Please verify your email settings.',
            'actionRequired' => 'configure_smtp',
            'actionLabel' => 'Configure Email Settings',
            'subscriberCount' => $subscriberCount
        ];
    }

    // Test actual SMTP connection if requested
    if ($testConnection) {
        try {
            require_once(__DIR__ . '/vendor/autoload.php');

              $mail = new PHPMailer\PHPMailer\PHPMailer(true);
              $mail->isSMTP();
              $mail->Host = $smtp_host;
              $mail->Port = isset($settings['smtp_port']) ? (int)$settings['smtp_port'] : 587;
              configureSmtpTransportSecurity($mail, 5);

             if ($smtp_secure !== 'none' && $smtp_secure !== '') {
                 $mail->SMTPSecure = $smtp_secure;
             }

             if ($smtp_auth) {
                 $mail->SMTPAuth = true;
                 $mail->Username = $settings['smtp_username'] ?? '';
                 $mail->Password = $settings['smtp_password'] ?? '';
             }

              if (!$mail->smtpConnect()) {
                 error_log('SMTP connection test failed');
                 return [
                     'canSend' => false,
                     'error' => 'Cannot connect to SMTP server. Please verify your email settings.',
                     'actionRequired' => 'configure_smtp',
                     'actionLabel' => 'Configure Email Settings',
                     'subscriberCount' => $subscriberCount
                 ];
             }

             $mail->smtpClose();
          } catch (\Exception $e) {
              error_log('SMTP connection test error: ' . redactSmtpDiagnostics($e->getMessage()));
             return [
                 'canSend' => false,
                 'error' => 'Cannot connect to SMTP server. Please verify your email settings.',
                 'actionRequired' => 'configure_smtp',
                 'actionLabel' => 'Configure Email Settings',
                 'subscriberCount' => $subscriberCount
             ];
         }
     }

    return [
        'canSend' => true,
        'subscriberCount' => $subscriberCount
    ];
}

/**
 * Send email notification to all active subscribers about a new post
 *
 * @param mysqli $db_conn Database connection
 * @param int $postId ID of the newly published post
 * @return array Result with success status and details
 */
function sendNewPostNotification(\mysqli $db_conn, int $postId): array
{
    // Get settings - notifications enabled and SMTP configuration
    $settings = getSettings($db_conn);
    if (!$settings || !$settings['notify_subscribers_on_post']) {
        return [
            'success' => false,
            'error' => 'Email notifications disabled in settings.',
            'actionRequired' => 'enable_notifications',
            'actionLabel' => 'Enable Email Notifications'
        ];
    }

    // Load SMTP settings from database only
    $smtp_host = $settings['smtp_host'] ?? null;
    $smtp_port = isset($settings['smtp_port']) ? (int)$settings['smtp_port'] : 587;
    $smtp_secure = $settings['smtp_secure'] ?? 'none';
    // Convert 'none' to empty string for PHPMailer
    if ($smtp_secure === 'none') {
        $smtp_secure = '';
    }
    $smtp_auth = isset($settings['smtp_auth']) ? (bool)$settings['smtp_auth'] : true;
    $smtp_username = $settings['smtp_username'] ?? '';
    $smtp_password = $settings['smtp_password'] ?? '';
    $smtp_from_email = $settings['smtp_from_email'] ?? null;
    $smtp_from_name = $settings['smtp_from_name'] ?? 'Post Portal';

     // Log SMTP configuration (redacted for security)
     error_log("SMTP Config - Port: {$smtp_port}, Secure: {$smtp_secure}, Auth: " . ($smtp_auth ? 'true' : 'false'));

    // Validate required SMTP settings
    $missingFields = [];
    if (!$smtp_host) {
        $missingFields[] = 'SMTP Host';
    }
    if (!$smtp_from_email) {
        $missingFields[] = 'From Email';
    }
    if (!empty($missingFields)) {
        return [
            'success' => false,
            'error' => 'SMTP configuration incomplete. Missing: ' . implode(', ', $missingFields),
            'actionRequired' => 'configure_smtp',
            'actionLabel' => 'Configure Email Settings'
        ];
    }

    // Enforce SMTP security policy before any other work
    if (!validateSmtpSecurityPolicy($smtp_auth, $smtp_secure)) {
        error_log('SMTP security policy violation: authentication attempted without encrypted transport');
        return [
            'success' => false,
            'error' => 'SMTP configuration error. Please verify your email settings.',
            'actionRequired' => 'configure_smtp',
            'actionLabel' => 'Configure Email Settings'
        ];
    }

    // Get post details
    $post = getPost($db_conn, $postId);
    if (!$post) {
        return ['success' => false, 'error' => 'Post not found'];
    }

    // Get all active subscribers
    $sql = 'SELECT email FROM newsletter_subscribers WHERE is_active = ?';
    $stmt = mysqli_prepare($db_conn, $sql);

    if (!$stmt) {
        return ['success' => false, 'error' => 'Failed to prepare subscriber fetch'];
    }

    $isActive = 1;
    mysqli_stmt_bind_param($stmt, 'i', $isActive);

    if (!mysqli_stmt_execute($stmt)) {
        return ['success' => false, 'error' => 'Failed to fetch subscribers'];
    }

    $result = mysqli_stmt_get_result($stmt);
    $subscribers = [];

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            if (!empty($row['email'])) {
                $subscribers[] = $row['email'];
            }
        }
    }

    // Early return if no subscribers - avoid initializing SMTP connection
    if (empty($subscribers)) {
        error_log('No active subscribers found - skipping email notification');
        return ['success' => true, 'sent' => 0, 'message' => 'No active subscribers'];
    }

    // Build email content - post data already decoded by getPost()
    $siteTitle = $settings['site_title'] ?: 'Post Portal';
    $postTitle = $post['title'] ?: 'New Post';
    $subject = $postTitle;

    // Get author name
    $authorName = '';
    if (!empty($post['author_first']) || !empty($post['author_last'])) {
        $authorName = trim(($post['author_first'] ?? '') . ' ' . ($post['author_last'] ?? ''));
    }
     if (empty($authorName)) {
         $authorName = 'Someone';
     }

     // Build notification URL using canonical app URL
     $postUrl = buildNotificationPostUrl($postId);
     if ($postUrl === null) {
         return [
             'success' => false,
             'error' => 'Configuration error. Please verify your email settings.',
             'actionRequired' => 'configure_smtp',
             'actionLabel' => 'Configure Email Settings'
         ];
     }

     // Get canonical base URL for relative URL conversion in email body
     $baseUrl = getCanonicalAppUrl();

    // Build HTML email body using ViewRenderer for consistent Smarty configuration
    $emailView = ViewRenderer::fromSmarty(null, false)->getSmarty();

    $includePostBody = (bool)$settings['email_include_post_body'];

    // Prepare common template variables (non-personalized)
    $emailView->assign('site_title', $siteTitle);
    $emailView->assign('post_url', $postUrl);
    $emailView->assign('base_url', $baseUrl);

    // Prepare template variables based on email type
    if ($includePostBody) {
        // Get body HTML - already decoded by getPost()
        $bodyHtml = $post['body_html'];

        // Convert relative URLs to absolute URLs
        $bodyHtml = preg_replace('/(src|href)=["\']\//', '$1="' . $baseUrl . '/', $bodyHtml);

        // Get hero image data if available
        $heroImageUrl = '';
        if (!empty($post['hero_media_id'])) {
            $heroMedia = getMedia($db_conn, (int)$post['hero_media_id']);
            if ($heroMedia && !empty($heroMedia['variants_json'])) {
                // Use 800w variant for email (optimal for email clients)
                $variants = json_decode($heroMedia['variants_json'], true);

                if (is_array($variants)) {
                    foreach ($variants as $v) {
                        if (isset($v['width']) && $v['width'] === 800 && isset($v['format']) && $v['format'] === 'jpg' && !empty($v['path'])) {
                            $heroImageUrl = $baseUrl . '/' . ltrim($v['path'], '/');
                            break;
                        }
                    }
                }
            }
        }

        // Assign variables for full email template
        $emailView->assign('post_title', $postTitle);
        $emailView->assign('hero_image_url', $heroImageUrl);
        $emailView->assign('hero_image_height', $post['hero_image_height'] ?? 100);
        $emailView->assign('hero_title_overlay', $post['hero_title_overlay'] ?? 1);
        $emailView->assign('hero_overlay_opacity', $post['hero_overlay_opacity'] ?? 0.70);
        $emailView->assign('body_html', $bodyHtml);
    } else {
        // Always derive excerpt from the latest body_html to avoid stale saved excerpts
        $excerpt = generateExcerpt($post['body_html'], 150);

        // Assign variables for excerpt email template
        $emailView->assign('author_name', $authorName);
        $emailView->assign('excerpt', $excerpt);
    }

    // Send emails individually to each subscriber with personalized unsubscribe link
    $sentCount = 0;
    $errors = [];

    try {
        require_once(__DIR__ . '/vendor/autoload.php');

         // Create PHPMailer instance once and reuse
         $mail = new PHPMailer\PHPMailer\PHPMailer(true);

         // Enable debug output for error logging (in production, errors are logged not displayed)
         $debugMode = getenv('DEBUG') === 'true' || getenv('DEBUG') === '1';
         $mail->SMTPDebug = $debugMode ? 3 : 0; // Verbose debugging in dev mode only
          $mail->Debugoutput = function (string $str, int $level) use ($debugMode): void {
              if ($debugMode) {
                  // Write SMTP diagnostics to owned log file
                  $logFile = getSmtpLogPath();
                  @file_put_contents($logFile, date('Y-m-d H:i:s') . " - " . trim(redactSmtpDiagnostics($str)) . "\n", FILE_APPEND);
              }
          };

         // Server settings
         $mail->isSMTP();
         $mail->Host = $smtp_host;
         $mail->Port = $smtp_port;
         configureSmtpTransportSecurity($mail, 5);
         $mail->SMTPKeepAlive = true; // Keep connection alive for multiple sends

         if ($smtp_auth) {
             $mail->SMTPAuth = true;
             $mail->Username = $smtp_username;
             $mail->Password = $smtp_password;
         } else {
             $mail->SMTPAuth = false;
         }

         if ($smtp_secure) {
             $mail->SMTPSecure = $smtp_secure;
         }

         // Note: Connection test is done in checkEmailConfiguration() before publishing
         // No need to test again here - if we get this far, SMTP was already verified

         // Sender - use configured from name and email
         $mail->setFrom($smtp_from_email, $smtp_from_name);

         // Content settings
         $mail->isHTML(true);
         $mail->CharSet = 'UTF-8';
         $mail->Encoding = 'base64';
         $mail->Subject = $subject;

        // Rate limiting variables - use database settings if available, otherwise config defaults
        $smtp_rate_limit = isset($settings['smtp_rate_limit']) ? (int)$settings['smtp_rate_limit'] : (isset($smtp_rate_limit) ? $smtp_rate_limit : 20);
        $smtp_rate_period = isset($settings['smtp_rate_period']) ? (int)$settings['smtp_rate_period'] : (isset($smtp_rate_period) ? $smtp_rate_period : 60);
        $smtp_batch_delay = isset($settings['smtp_batch_delay']) ? (float)$settings['smtp_batch_delay'] : (isset($smtp_batch_delay) ? $smtp_batch_delay : 0.5);

        $rateLimitEnabled = $smtp_rate_limit > 0;
        $batchDelay = $smtp_batch_delay;
        $emailsSentInCurrentPeriod = 0;
        $periodStartTime = microtime(true);

        // Send to each subscriber individually with personalized unsubscribe link
        foreach ($subscribers as $email) {
            try {
                // Check rate limit - if we've hit the limit for this period, wait
                if ($rateLimitEnabled && $emailsSentInCurrentPeriod >= $smtp_rate_limit) {
                    $elapsedTime = microtime(true) - $periodStartTime;
                    $remainingTime = $smtp_rate_period - $elapsedTime;

                    if ($remainingTime > 0) {
                        error_log("Rate limit reached ({$smtp_rate_limit} emails per {$smtp_rate_period}s). Pausing for {$remainingTime}s...");
                        sleep((int) ceil($remainingTime));
                    }

                    // Reset counter and timer for new period
                    $emailsSentInCurrentPeriod = 0;
                    $periodStartTime = microtime(true);
                }

                // Generate personalized unsubscribe token for this email
                $unsubscribeToken = generateUnsubscribeToken($email);
                $unsubscribeUrl = "{$baseUrl}/api/unsubscribe.php?token={$unsubscribeToken}";

                // Assign the personalized unsubscribe URL to template
                $emailView->assign('unsubscribe_url', $unsubscribeUrl);

                // Render the email body with personalized unsubscribe link
                if ($includePostBody) {
                    $emailBody = $emailView->fetch('email_notification_full.tpl');
                } else {
                    $emailBody = $emailView->fetch('email_notification_excerpt.tpl');
                }

                // Clear previous recipients
                $mail->clearAddresses();

                // Add this subscriber
                $mail->addAddress($email);

                // Set body
                $mail->Body = $emailBody;
                $mail->AltBody = strip_tags($emailBody); // Plain text fallback

                // Send the email
                $mail->send();
                $sentCount++;
                $emailsSentInCurrentPeriod++;

                // Add delay between emails if configured
                if ($batchDelay > 0 && $sentCount < count($subscribers)) {
                    usleep((int)($batchDelay * 1000000)); // Convert to microseconds
                }

             } catch (Exception $e) {
                 error_log("Failed to send notification to recipient " . (count($errors) + 1) . ": " . redactSmtpDiagnostics($e->getMessage()));
                 $errors[] = $email;
             }
        }

        if ($sentCount > 0) {
            $failedCount = count($errors);
            return [
                'success' => true,
                'sent' => $sentCount,
                'failed' => $failedCount,
                'message' => "Notifications sent to {$sentCount} subscriber(s)" . ($failedCount > 0 ? " ({$failedCount} failed)" : '')
            ];
        } else {
            return [
                'success' => false,
                'error' => 'Failed to send emails to any subscribers. Please verify your SMTP settings.',
                'actionRequired' => 'configure_smtp',
                'actionLabel' => 'Configure Email Settings'
            ];
        }

     } catch (Exception $e) {
         error_log('Failed to send post notification: ' . redactSmtpDiagnostics($e->getMessage()));
         return [
             'success' => false,
             'error' => 'Failed to send email. Please verify your SMTP settings.',
             'actionRequired' => 'configure_smtp',
             'actionLabel' => 'Configure Email Settings'
         ];
     }
}

?>

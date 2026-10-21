<?php

declare(strict_types=1);

namespace PostPortal\Repository;

use mysqli;

/**
 * Post repository implementation using prepared statements
 */
class PostRepository extends BaseRepository implements PostRepositoryInterface
{
    /**
     * Get a post by ID
     *
     * @param int $id Post ID
     * @return array<string, mixed>|null Post data or null if not found
     */
    public function findById(int $id): ?array
    {
        $sql = 'SELECT * FROM posts WHERE id = ? LIMIT 1';
        return $this->fetchOne($sql, [$id]);
    }

    /**
     * Get all posts with pagination
     *
     * @param int $limit Number of posts to retrieve
     * @param int $offset Offset for pagination
     * @param string|null $status Filter by status (draft, published, or null for all)
     * @return array<int, array<string, mixed>> Array of posts
     */
    public function findAll(int $limit = 50, int $offset = 0, ?string $status = null): array
    {
        if ($status !== null) {
            $sql = 'SELECT * FROM posts WHERE status = ? ORDER BY created_at DESC LIMIT ? OFFSET ?';
            return $this->fetchAll($sql, [$status, $limit, $offset]);
        }

        $sql = 'SELECT * FROM posts ORDER BY created_at DESC LIMIT ? OFFSET ?';
        return $this->fetchAll($sql, [$limit, $offset]);
    }

    /**
     * Create a new post
     *
     * @param array<string, mixed> $data Post data
     * @return int Newly created post ID
     */
    public function create(array $data): int
    {
        $fields = [];
        $placeholders = [];
        $params = [];

        foreach ($data as $key => $value) {
            $fields[] = $key;
            $placeholders[] = '?';
            $params[] = $value;
        }

        $sql = 'INSERT INTO posts (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $this->execute($sql, $params);

        return $this->lastInsertId();
    }

    /**
     * Update a post
     *
     * @param int $id Post ID
     * @param array<string, mixed> $data Post data to update
     * @return bool True on success
     */
    public function update(int $id, array $data): bool
    {
        if (empty($data)) {
            return true;
        }

        $fields = [];
        $params = [];

        foreach ($data as $key => $value) {
            $fields[] = "$key = ?";
            $params[] = $value;
        }

        $params[] = $id;

        $sql = 'UPDATE posts SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $this->execute($sql, $params);

        return $this->affectedRows() > 0;
    }

    /**
     * Delete a post
     *
     * @param int $id Post ID
     * @return bool True on success
     */
    public function delete(int $id): bool
    {
        $sql = 'DELETE FROM posts WHERE id = ?';
        $this->execute($sql, [$id]);

        return $this->affectedRows() > 0;
    }

    /**
     * Update draft-only fields for a post.
     */
    public function updateDraftFields(int $id, array $data): bool
    {
        if (empty($data)) {
            return true;
        }

        try {
            $fields = [];
            $params = [];

            foreach ($data as $key => $value) {
                $fields[] = "$key = ?";
                $params[] = $value;
            }

            $params[] = $id;

            $sql = 'UPDATE posts SET ' . implode(', ', $fields) . ', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND deleted_at IS NULL';
            $this->execute($sql, $params);

            // Treat no-op updates as success; execute would throw on real errors.
            return true;
        } catch (\Throwable $e) {
            error_log('PostRepository::updateDraftFields error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get post body HTML
     *
     * @param int $id Post ID
     * @return string|null Post body HTML or null
     */
    public function getBodyHtml(int $id): ?string
    {
        $sql = 'SELECT body_html FROM posts WHERE id = ? LIMIT 1';
        $result = $this->fetchColumn($sql, [$id]);

        return $result !== null ? (string)$result : null;
    }

    /**
     * Get post status and published_at
     *
     * @param int $id Post ID
     * @return array<string, mixed>|null Post status info or null
     */
    public function getStatusInfo(int $id): ?array
    {
        $sql = 'SELECT status, published_at FROM posts WHERE id = ? LIMIT 1';
        return $this->fetchOne($sql, [$id]);
    }

    /**
     * Update post excerpt
     *
     * @param int $id Post ID
     * @param string $excerpt New excerpt
     * @return bool True on success
     */
    public function updateExcerpt(int $id, string $excerpt): bool
    {
        $sql = 'UPDATE posts SET excerpt = ? WHERE id = ?';
        $this->execute($sql, [$excerpt, $id]);

        return $this->affectedRows() > 0;
    }

    /**
     * Count posts optionally filtered by status.
     */
    public function countAll(?string $status = null): int
    {
        if ($status !== null) {
            $sql = 'SELECT COUNT(*) as c FROM posts WHERE status = ?';
            $count = $this->fetchColumn($sql, [$status]);
            return $count !== null ? (int) $count : 0;
        }

        $count = $this->fetchColumn('SELECT COUNT(*) as c FROM posts');
        return $count !== null ? (int) $count : 0;
    }

    /**
     * Get recent posts for dashboards.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecent(int $limit = 5): array
    {
        $sql = 'SELECT id, title, published_at, created_at FROM posts ORDER BY created_at DESC LIMIT ?';
        return $this->fetchAll($sql, [$limit]);
    }

    /**
     * Count posts excluding soft-deleted ones.
     */
    public function countNonDeleted(): int
    {
        $count = $this->fetchColumn('SELECT COUNT(*) as c FROM posts WHERE deleted_at IS NULL');
        return $count !== null ? (int) $count : 0;
    }

    /**
     * Get posts with author info, paginated, excluding soft-deleted.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getWithAuthor(int $limit, int $offset): array
    {
        $sql = 'SELECT p.*, u.first AS author_first, u.last AS author_last, u.username AS author_username
                FROM posts p
                LEFT JOIN users u ON p.created_by_user_id = u.username
                WHERE p.deleted_at IS NULL
                ORDER BY p.created_at DESC
                LIMIT ? OFFSET ?';

        return $this->fetchAll($sql, [$limit, $offset]);
    }

    /**
     * Get published posts with hero media variants and author info.
     */
    public function getPublishedWithMedia(int $limit, int $offset): array
    {
        $sql = 'SELECT p.*, m.variants_json AS hero_variants,
                       u.first AS author_first, u.last AS author_last, u.username AS author_username
                FROM posts p
                LEFT JOIN media m ON p.hero_media_id = m.id
                LEFT JOIN users u ON p.created_by_user_id = u.username
                WHERE p.status = "published" AND p.deleted_at IS NULL
                ORDER BY COALESCE(p.published_at, p.created_at) DESC
                LIMIT ? OFFSET ?';

        return $this->fetchAll($sql, [$limit, $offset]);
    }

    /**
     * Get posts with media for admin timeline (includes drafts).
     * Returns published posts plus draft posts, ordered by date.
     * Drafts use their draft content fields for display.
     */
    public function getPostsWithMediaIncludingDrafts(int $limit, int $offset): array
    {
        $sql = 'SELECT p.*,
                       m.variants_json AS hero_variants,
                       u.first AS author_first, u.last AS author_last, u.username AS author_username
                FROM posts p
                LEFT JOIN media m ON COALESCE(p.hero_media_id_draft, p.hero_media_id) = m.id
                LEFT JOIN users u ON p.created_by_user_id = u.username
                WHERE p.deleted_at IS NULL
                ORDER BY COALESCE(p.published_at, p.created_at) DESC
                LIMIT ? OFFSET ?';

        return $this->fetchAll($sql, [$limit, $offset]);
    }

    /**
     * Get a single post with author info.
     */
    public function findWithAuthor(int $id): ?array
    {
        $sql = 'SELECT p.*, u.first AS author_first, u.last AS author_last, u.username AS author_username
                FROM posts p
                LEFT JOIN users u ON p.created_by_user_id = u.username
                WHERE p.id = ? AND p.deleted_at IS NULL
                LIMIT 1';

        return $this->fetchOne($sql, [$id]);
    }

    /**
     * Increment view/impression counters for a published post.
     */
    public function incrementMetrics(int $postId, int $impressionInc, int $uniqueImpressionInc, int $viewInc, int $uniqueViewInc): bool
    {
        $sql = 'UPDATE posts
                SET impression_count = impression_count + ?,
                    unique_impression_count = unique_impression_count + ?,
                    view_count = view_count + ?,
                    unique_view_count = unique_view_count + ?,
                    updated_at = updated_at
                WHERE id = ? AND status = "published" AND deleted_at IS NULL';

        $this->execute($sql, [$impressionInc, $uniqueImpressionInc, $viewInc, $uniqueViewInc, $postId]);

        return $this->affectedRows() > 0;
    }

    /**
     * Describe how a media item is referenced by posts (hero/gallery).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMediaUsage(int $mediaId): array
    {
        $usage = [];

        $heroSql = 'SELECT id, title FROM posts WHERE hero_media_id = ? AND deleted_at IS NULL';
        $heroRows = $this->fetchAll($heroSql, [$mediaId]);
        foreach ($heroRows as $row) {
            $usage[] = [
                'id' => (int) ($row['id'] ?? 0),
                'title' => $row['title'] ?: '(untitled)',
                'usage' => 'hero image',
            ];
        }

        $gallerySql = 'SELECT id, title, gallery_media_ids FROM posts WHERE gallery_media_ids IS NOT NULL AND deleted_at IS NULL';
        $galleryRows = $this->fetchAll($gallerySql);
        foreach ($galleryRows as $row) {
            $galleryIds = json_decode((string) ($row['gallery_media_ids'] ?? ''), true);
            if (is_array($galleryIds) && in_array($mediaId, $galleryIds, true)) {
                $usage[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'title' => $row['title'] ?: '(untitled)',
                    'usage' => 'gallery',
                ];
            }
        }

        return $usage;
    }
}

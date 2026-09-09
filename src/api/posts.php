<?php

declare(strict_types=1);

use PostPortal\Http\ApiHandler;
use PostPortal\Http\ErrorResponse;
use PostPortal\Lib\MediaProcessor;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/MediaProcessor.php';

ApiHandler::handle(function (): void {
    ['container' => $container] = bootstrapApi();
    $postService = $container->getPostService();
    $mediaService = $container->getMediaService();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        ErrorResponse::json(405, 'Method not allowed');
    }

    // Check if user is admin (explicit true)
    $isAdmin = ($_SESSION['isadmin'] ?? null) === true;

    if (isset($_GET['id'])) {
        $id = (int) $_GET['id'];
        $post = $postService->getPost($id);

        // Allow admins to view draft posts, but public only sees published
        $isDraft = ($post['status'] ?? '') === 'draft';
        if (!$post || (!$isAdmin && $isDraft) || ($post['status'] ?? '') !== 'published' && !$isAdmin) {
            ErrorResponse::notFound('Not found');
        }

        // For draft posts, use draft content fields for display
        if ($isDraft && $isAdmin) {
            if (!empty($post['title_draft'])) {
                $post['title'] = $post['title_draft'];
            }
            if (!empty($post['body_html_draft'])) {
                $post['body_html'] = $post['body_html_draft'];
            }
            // Use draft hero media ID - allow null to clear the image
            if (array_key_exists('hero_media_id_draft', $post)) {
                $post['hero_media_id'] = $post['hero_media_id_draft'];
            }
            if (isset($post['hero_image_height_draft'])) {
                $post['hero_image_height'] = $post['hero_image_height_draft'];
            }
            if (isset($post['hero_overlay_opacity_draft'])) {
                $post['hero_overlay_opacity'] = $post['hero_overlay_opacity_draft'];
            }
            if (isset($post['hero_title_overlay_draft'])) {
                $post['hero_title_overlay'] = $post['hero_title_overlay_draft'];
            }
            // Use draft gallery media IDs - allow empty array to clear gallery
            if (array_key_exists('gallery_media_ids_draft', $post)) {
                $post['gallery_media_ids'] = $post['gallery_media_ids_draft'];
            }
        }

        $post['hero_srcset_jpg'] = '';
        $post['hero_srcset_webp'] = '';
        if (!empty($post['hero_media_id'])) {
            $media = $mediaService->getMedia((int) $post['hero_media_id']);
            if ($media && !empty($media['variants_json'])) {
                $post['hero_srcset_jpg'] = MediaProcessor::generateSrcset($media['variants_json'], 'jpg');
                $post['hero_srcset_webp'] = MediaProcessor::generateSrcset($media['variants_json'], 'webp');
            }
        }

        $post['gallery_images'] = [];
        if (!empty($post['gallery_media_ids'])) {
            $galleryIds = json_decode((string) $post['gallery_media_ids'], true);
            if (is_array($galleryIds)) {
                foreach ($galleryIds as $galleryId) {
                    $galleryMedia = $mediaService->getMedia((int) $galleryId);
                    if ($galleryMedia) {
                        $post['gallery_images'][] = [
                            'id' => $galleryMedia['id'],
                            'alt_text' => $galleryMedia['alt_text'] ?? '',
                            'width' => $galleryMedia['width'],
                            'height' => $galleryMedia['height'],
                            'srcset_jpg' => MediaProcessor::generateSrcset($galleryMedia['variants_json'], 'jpg'),
                            'srcset_webp' => MediaProcessor::generateSrcset($galleryMedia['variants_json'], 'webp'),
                            'original_path' => $galleryMedia['storage_path'],
                        ];
                    }
                }
            }
        }

        ErrorResponse::success(['post' => $post]);
    }

    $limit = isset($_GET['limit']) ? max(1, (int) $_GET['limit']) : 10;
    $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

    // For admins, include draft posts; for public, only published
    if ($isAdmin) {
        $posts = $postService->getPostsIncludingDrafts($limit, $offset);
    } else {
        $posts = $postService->getPublishedPosts($limit, $offset);
    }

    foreach ($posts as &$p) {
        $p['hero_srcset_jpg'] = '';
        $p['hero_srcset_webp'] = '';
        if (!empty($p['hero_variants'])) {
            $p['hero_srcset_jpg'] = MediaProcessor::generateSrcset($p['hero_variants'], 'jpg');
            $p['hero_srcset_webp'] = MediaProcessor::generateSrcset($p['hero_variants'], 'webp');
        }
    }
    unset($p);

    ErrorResponse::success(['posts' => $posts]);
});

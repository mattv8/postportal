<?php

declare(strict_types=1);

namespace PostPortal\Page;

use mysqli;
use PostPortal\Container\ServiceContainer;
use PostPortal\Service\MediaServiceInterface;
use PostPortal\Service\PostServiceInterface;
use PostPortal\Repository\SettingsRepository;
use PostPortal\Lib\MediaProcessor;

require_once __DIR__ . '/../lib/MediaProcessor.php';

class HomePage
{
    private mysqli $db;
    private string $defaultLogo;
    private PostServiceInterface $postService;
    private MediaServiceInterface $mediaService;
    private SettingsRepository $settingsRepository;

    public function __construct(ServiceContainer $container, mysqli $db, string $defaultLogo)
    {
        $this->db = $db;
        $this->defaultLogo = $defaultLogo;
        $this->postService = $container->getPostService();
        $this->mediaService = $container->getMediaService();
        $this->settingsRepository = $container->getSettingsRepository();
    }

    /**
     * Build template data for the public home page.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $settings = $this->settingsRepository->getSettings() ?? [];
        $settings = $this->applyDefaults($settings);

        $isAuthenticated = !empty($_SESSION['authenticated']);
        $isAdmin = ($_SESSION['isadmin'] ?? null) === true;

        // For admins, include draft posts; for public, only published
        if ($isAdmin) {
            $posts = $this->postService->getPostsIncludingDrafts(5, 0);
        } else {
            $posts = $this->postService->getPublishedPosts(5, 0);
        }
        $posts = $this->attachPostSrcsets($posts);

        $heroSrcsets = $this->buildMediaSrcsets($settings['hero_media_id'] ?? null);
        $footerSrcsets = $this->buildMediaSrcsets($settings['footer_media_id'] ?? null);
        $donationQrSrcsets = $this->buildMediaSrcsets($settings['donation_qr_media_id'] ?? null);

        $logoUrls = \getLogoUrls($this->db, $settings['logo_media_id'] ?? null, $this->defaultLogo);
        $faviconUrls = \getFaviconUrls($this->db, $settings['favicon_media_id'] ?? null);

        return [
            'settings' => $settings,
            'posts' => $posts,
            'hero_jpg' => $heroSrcsets['jpg'],
            'hero_webp' => $heroSrcsets['webp'],
            'footer_jpg' => $footerSrcsets['jpg'],
            'footer_webp' => $footerSrcsets['webp'],
            'donation_qr_jpg' => $donationQrSrcsets['jpg'],
            'donation_qr_webp' => $donationQrSrcsets['webp'],
            'logo_url' => $logoUrls['logo_url'],
            'logo_srcset_png' => $logoUrls['logo_srcset_png'],
            'logo_srcset_webp' => $logoUrls['logo_srcset_webp'],
            'favicon_ico' => $faviconUrls['favicon_ico'],
            'favicon_svg' => $faviconUrls['favicon_svg'],
            'favicon_16' => $faviconUrls['favicon_16'],
            'favicon_32' => $faviconUrls['favicon_32'],
            'favicon_192' => $faviconUrls['favicon_192'],
            'favicon_512' => $faviconUrls['favicon_512'],
            'page_title' => $settings['site_title'] ?? '',
            'is_authenticated' => $isAuthenticated,
            'is_admin' => $isAdmin,
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function applyDefaults(array $settings): array
    {
        $defaults = [
            'hero_height' => 100,
            'show_footer' => 1,
            'footer_layout' => 'double',
            'footer_media_id' => null,
            'footer_height' => 30,
            'footer_overlay_opacity' => 0.50,
            'footer_overlay_color' => '#000000',
            'footer_column1_html' => '',
            'footer_column2_html' => '',
            'show_mailing_list' => 1,
            'mailing_list_html' => '<p>Subscribe to get notified when we post updates.</p>',
            'newsletter_position' => 'sidebar',
            'newsletter_position_scope' => 'mobile_only',
            'show_view_counts' => 0,
            'show_impression_counts' => 0,
            'ignore_admin_tracking' => 1,
        ];

        foreach ($defaults as $key => $value) {
            if (!isset($settings[$key])) {
                $settings[$key] = $value;
            }
        }

        return $settings;
    }

    /**
     * @param array<int, array<string, mixed>> $posts
     * @return array<int, array<string, mixed>>
     */
    private function attachPostSrcsets(array $posts): array
    {
        foreach ($posts as &$post) {
            $post['hero_srcset_jpg'] = '';
            $post['hero_srcset_webp'] = '';

            if (!empty($post['hero_variants'])) {
                $post['hero_srcset_jpg'] = MediaProcessor::generateSrcset($post['hero_variants'], 'jpg');
                $post['hero_srcset_webp'] = MediaProcessor::generateSrcset($post['hero_variants'], 'webp');
            }
        }

        unset($post);

        return $posts;
    }

    /**
     * @param int|string|null $mediaId
     * @return array<string, string>
     */
    private function buildMediaSrcsets($mediaId): array
    {
        $jpg = '';
        $webp = '';

        if (!empty($mediaId)) {
            $media = $this->mediaService->getMedia((int) $mediaId);
            if ($media && !empty($media['variants_json'])) {
                $jpg = MediaProcessor::generateSrcset($media['variants_json'], 'jpg');
                $webp = MediaProcessor::generateSrcset($media['variants_json'], 'webp');
            }
        }

        return [
            'jpg' => $jpg,
            'webp' => $webp,
        ];
    }
}

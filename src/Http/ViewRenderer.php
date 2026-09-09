<?php

declare(strict_types=1);

namespace PostPortal\Http;

// Explicit require for development container where autoload paths differ
if (!class_exists(\PostPortal\Lib\AssetHelper::class)) {
    require_once dirname(__DIR__) . '/Lib/AssetHelper.php';
}

use PostPortal\Lib\AssetHelper;
use Smarty\Smarty;

/**
 * Lightweight wrapper around Smarty to avoid relying on globals.
 */
class ViewRenderer
{
    private Smarty $smarty;
    private AssetHelper $assetHelper;

    public function __construct(Smarty $smarty, ?AssetHelper $assetHelper = null)
    {
        $this->smarty = $smarty;
        $this->assetHelper = $assetHelper ?? new AssetHelper();
    }

    public static function fromSmarty(?Smarty $smarty, bool $debug = false): self
    {
        if ($smarty instanceof Smarty) {
            $smarty->debugging = $debug;
            $renderer = new self($smarty);
            $renderer->registerAssetHelpers();
            return $renderer;
        }
        // Fall back to a new Composer-managed Smarty instance when none was provided.
        $instance = new Smarty();
        $instance->setTemplateDir([
            __DIR__ . '/../templates',
            __DIR__ . '/..',
        ]);
        $instance->setCompileDir(__DIR__ . '/../cache');
        $instance->setCacheDir(__DIR__ . '/../cache');
        $instance->escape_html = false;
        $instance->debugging = $debug;

        // Register PHP functions needed by templates (Smarty 5.x compatibility)
        $instance->registerPlugin('modifier', 'file_exists', 'file_exists');
        $instance->registerPlugin('modifier', 'in_array', 'in_array');
        $instance->registerPlugin('modifier', 'empty', function($val) { return empty($val); });
        $instance->registerPlugin('modifier', 'ucfirst', 'ucfirst');

        $renderer = new self($instance);
        $renderer->registerAssetHelpers();
        return $renderer;
    }

    /**
     * Register Smarty plugins for asset handling (cache-busting, bundles).
     */
    private function registerAssetHelpers(): void
    {
        $assetHelper = $this->assetHelper;

        // {asset_js file="admin.js"} - outputs versioned JS URL
        $this->smarty->registerPlugin('function', 'asset_js', function ($params) use ($assetHelper) {
            $file = $params['file'] ?? '';
            return $assetHelper->js($file);
        });

        // {asset_css file="custom.css"} - outputs versioned CSS URL
        $this->smarty->registerPlugin('function', 'asset_css', function ($params) use ($assetHelper) {
            $file = $params['file'] ?? '';
            return $assetHelper->css($file);
        });

        // {bundle_url name="core.bundle.js"} - outputs bundle URL (production) or empty string (dev)
        $this->smarty->registerPlugin('function', 'bundle_url', function ($params) use ($assetHelper) {
            $name = $params['name'] ?? '';
            $bundle = $assetHelper->bundle($name);
            return $bundle['url'] ?? '';
        });

        // Make asset helper available as template variable for bundle logic
        $this->smarty->assign('_assets', $assetHelper);
        $this->smarty->assign('_bundle_mode', $assetHelper->isBundleMode());
    }

    /**
     * Assign an associative array of template variables.
     *
     * @param array<string, mixed> $data
     */
    public function assign(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->smarty->assign($key, $value);
        }
    }

    public function getSmarty(): Smarty
    {
        return $this->smarty;
    }

    /**
     * Get the asset helper instance.
     */
    public function getAssetHelper(): AssetHelper
    {
        return $this->assetHelper;
    }
}

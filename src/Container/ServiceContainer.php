<?php

declare(strict_types=1);

namespace PostPortal\Container;

use mysqli;
use PostPortal\Repository\PostRepository;
use PostPortal\Repository\MediaRepository;
use PostPortal\Repository\NewsletterRepository;
use PostPortal\Repository\NewsletterThrottleRepository;
use PostPortal\Repository\NewsletterThrottleRepositoryInterface;
use PostPortal\Repository\SettingsRepository;
use PostPortal\Repository\UserRepository;
use PostPortal\Service\PostService;
use PostPortal\Service\PostServiceInterface;
use PostPortal\Service\MediaService;
use PostPortal\Service\MediaServiceInterface;
use PostPortal\Service\NewsletterService;
use PostPortal\Service\NewsletterServiceInterface;
use PostPortal\Service\NewsletterVerificationMailer;
use PostPortal\Service\NewsletterVerificationMailerInterface;
use PostPortal\Service\UserService;
use PostPortal\Service\UserServiceInterface;
use Smarty\Smarty;

/**
 * Simple service container for dependency injection
 * Provides centralized access to repositories and services
 */
class ServiceContainer
{
    private mysqli $db;
    private static ?ServiceContainer $instance = null;

    /** @var array<string, object> */
    private array $services = [];

    private function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Get singleton instance of the container
     */
    public static function getInstance(mysqli $db): self
    {
        if (self::$instance === null) {
            self::$instance = new self($db);
        }
        return self::$instance;
    }

    /**
     * Reset the singleton instance (useful for testing)
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Get PostRepository instance
     */
    public function getPostRepository(): PostRepository
     {
        if (!isset($this->services['PostRepository'])) {
            $this->services['PostRepository'] = new PostRepository($this->db);
        }
        /** @var PostRepository */
        return $this->services['PostRepository'];
    }

    /**
     * Get MediaRepository instance
     */
    public function getMediaRepository(): MediaRepository
    {
        if (!isset($this->services['MediaRepository'])) {
            $this->services['MediaRepository'] = new MediaRepository($this->db);
        }
        /** @var MediaRepository */
        return $this->services['MediaRepository'];
    }

    /**
     * Get NewsletterRepository instance
     */
    public function getNewsletterRepository(): NewsletterRepository
    {
        if (!isset($this->services['NewsletterRepository'])) {
            $this->services['NewsletterRepository'] = new NewsletterRepository($this->db);
        }
        /** @var NewsletterRepository */
        return $this->services['NewsletterRepository'];
    }

    public function getNewsletterThrottleRepository(): NewsletterThrottleRepositoryInterface
    {
        if (!isset($this->services['NewsletterThrottleRepository'])) {
            $this->services['NewsletterThrottleRepository'] = new NewsletterThrottleRepository($this->db);
        }
        /** @var NewsletterThrottleRepositoryInterface */
        return $this->services['NewsletterThrottleRepository'];
    }

    /**
     * Get SettingsRepository instance
     */
    public function getSettingsRepository(): SettingsRepository
    {
        if (!isset($this->services['SettingsRepository'])) {
            $this->services['SettingsRepository'] = new SettingsRepository($this->db);
        }
        /** @var SettingsRepository */
        return $this->services['SettingsRepository'];
    }

    /**
     * Get PostService instance
     */
    public function getPostService(): PostServiceInterface
    {
        if (!isset($this->services['PostService'])) {
            $this->services['PostService'] = new PostService(
                $this->getPostRepository()
            );
        }
        /** @var PostServiceInterface */
        return $this->services['PostService'];
    }

    /**
     * Get MediaService instance
     */
    public function getMediaService(): MediaServiceInterface
    {
        if (!isset($this->services['MediaService'])) {
            $this->services['MediaService'] = new MediaService(
                $this->getMediaRepository(),
                $this->getPostRepository(),
                $this->getSettingsRepository()
            );
        }
        /** @var MediaServiceInterface */
        return $this->services['MediaService'];
    }

    /**
     * Get NewsletterService instance
     */
    public function getNewsletterService(): NewsletterServiceInterface
    {
        if (!isset($this->services['NewsletterService'])) {
            $this->services['NewsletterService'] = new NewsletterService(
                $this->getNewsletterRepository(),
                $this->getNewsletterVerificationMailer()
            );
        }
        /** @var NewsletterServiceInterface */
        return $this->services['NewsletterService'];
    }

    /**
     * Get UserRepository instance
     */
    public function getUserRepository(): UserRepository
    {
        if (!isset($this->services['UserRepository'])) {
            $this->services['UserRepository'] = new UserRepository($this->db);
        }
        /** @var UserRepository */
        return $this->services['UserRepository'];
    }

    /**
     * Get UserService instance
     */
    public function getUserService(): UserServiceInterface
    {
        if (!isset($this->services['UserService'])) {
            $this->services['UserService'] = new UserService(
                $this->getUserRepository()
            );
        }
        /** @var UserServiceInterface */
        return $this->services['UserService'];
    }

    /**
     * Get NewsletterVerificationMailer instance
     */
    public function getNewsletterVerificationMailer(): NewsletterVerificationMailerInterface
    {
        if (!isset($this->services['NewsletterVerificationMailer'])) {
            global $smarty;
            $smartyInstance = $smarty instanceof Smarty ? $smarty : null;
            if ($smartyInstance === null) {
                $templateDir = dirname(__DIR__) . '/templates';
                $cacheDir = dirname(__DIR__) . '/cache';
                if (!is_dir($templateDir) || !is_dir($cacheDir) || !is_writable($cacheDir)) {
                    throw new \RuntimeException('Newsletter template cache is not configured');
                }
                $smartyInstance = new Smarty();
                $smartyInstance->setTemplateDir([$templateDir, dirname(__DIR__)]);
                $smartyInstance->setCompileDir($cacheDir);
                $smartyInstance->setCacheDir($cacheDir);
            }
            $this->services['NewsletterVerificationMailer'] = new NewsletterVerificationMailer(
                $this->getSettingsRepository(),
                $smartyInstance
            );
        }
        /** @var NewsletterVerificationMailerInterface */
        return $this->services['NewsletterVerificationMailer'];
    }

    /**
     * Get database connection
     */
    public function getDb(): mysqli
    {
        return $this->db;
    }
}

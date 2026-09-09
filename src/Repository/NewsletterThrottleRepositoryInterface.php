<?php

declare(strict_types=1);

namespace PostPortal\Repository;

interface NewsletterThrottleRepositoryInterface
{
    public function isThrottled(string $sourceKey, int $maxAttemptsPerWindow = 5, int $windowSeconds = 3600): bool;
}

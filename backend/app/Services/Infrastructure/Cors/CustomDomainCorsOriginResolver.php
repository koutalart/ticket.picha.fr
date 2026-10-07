<?php

namespace HiEvents\Services\Infrastructure\Cors;

use HiEvents\DomainObjects\Status\OrganizerStatus;
use HiEvents\Helper\CustomDomainHelper;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

class CustomDomainCorsOriginResolver
{
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly CacheRepository              $cache,
    )
    {
    }

    public function isAllowedOrigin(string $origin): bool
    {
        if (!str_starts_with($origin, 'https://')) {
            return false;
        }

        $domain = CustomDomainHelper::normalize($origin);
        if ($domain === null || !CustomDomainHelper::isValid($domain)) {
            return false;
        }

        return $this->cache->remember(
            self::cacheKey($domain),
            self::CACHE_TTL_SECONDS,
            function () use ($domain): bool {
                $organizer = $this->organizerRepository->findFirstWhere(['custom_domain' => $domain]);

                return $organizer !== null && $organizer->getStatus() !== OrganizerStatus::ARCHIVED->name;
            }
        );
    }

    public function forget(?string $domain): void
    {
        $domain = CustomDomainHelper::normalize($domain);
        if ($domain !== null) {
            $this->cache->forget(self::cacheKey($domain));
        }
    }

    private static function cacheKey(string $domain): string
    {
        return 'cors_custom_domain:' . $domain;
    }
}

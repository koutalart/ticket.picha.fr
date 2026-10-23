<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\OrganizerCalendar;

use Carbon\CarbonInterface;
use HiEvents\DomainObjects\OrganizerCalendarMediaDomainObject;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Routing\UrlGenerator;

/**
 * Root-relative signed URLs, so the page loads its media from its own origin, custom domains included.
 * The URL ends with the file name (players and the front detect videos by extension) and carries the access id,
 * so revoking the access also cuts its media URLs.
 */
class OrganizerCalendarMediaUrlGenerator
{
    public const ROUTE_NAME = 'organizer-calendars.media';

    public function __construct(
        private readonly UrlGenerator $urlGenerator,
        private readonly ConfigRepository $config,
    ) {}

    public function generate(
        string $slug,
        OrganizerCalendarMediaDomainObject $media,
        int $accessId,
        CarbonInterface $expiresAt,
    ): string {
        $relativeUrl = $this->urlGenerator->temporarySignedRoute(
            name: self::ROUTE_NAME,
            expiration: $expiresAt,
            parameters: [
                'slug' => $slug,
                'media_id' => $media->getId(),
                'file_name' => self::publicFileName($media->getFileName()),
                'a' => $accessId,
            ],
            absolute: false,
        );

        return $this->apiPathPrefix().'/'.ltrim($relativeUrl, '/');
    }

    public static function publicFileName(string $fileName): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($fileName)) ?? '';

        return trim($name, '-') !== '' ? $name : 'media';
    }

    private function apiPathPrefix(): string
    {
        $path = parse_url((string) $this->config->get('app.api_url'), PHP_URL_PATH);

        return rtrim(is_string($path) ? $path : '', '/');
    }
}

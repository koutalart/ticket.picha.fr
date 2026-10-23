<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\OrganizerCalendar;

use Carbon\CarbonInterface;
use HiEvents\DomainObjects\Generated\OrganizerCalendarMediaDomainObjectAbstract as Media;
use HiEvents\DomainObjects\OrganizerCalendarDomainObject;
use HiEvents\DomainObjects\OrganizerCalendarMediaDomainObject;
use HiEvents\Repository\Interfaces\OrganizerCalendarMediaRepositoryInterface;

class OrganizerCalendarContentResolver
{
    public function __construct(
        private readonly OrganizerCalendarMediaRepositoryInterface $mediaRepository,
        private readonly OrganizerCalendarMediaReferences $references,
        private readonly OrganizerCalendarMediaUrlGenerator $urlGenerator,
    ) {}

    /**
     * The stored content with every media reference replaced by a signed URL valid until $expiresAt.
     */
    public function resolve(OrganizerCalendarDomainObject $calendar, int $accessId, CarbonInterface $expiresAt): array
    {
        $content = $calendar->getContent();
        if (! is_array($content)) {
            $content = json_decode((string) $content, true) ?: [];
        }

        /** @var array<int, OrganizerCalendarMediaDomainObject> $media */
        $media = $this->mediaRepository
            ->findWhere([Media::ORGANIZER_CALENDAR_ID => $calendar->getId()])
            ->keyBy(fn (OrganizerCalendarMediaDomainObject $item) => $item->getId())
            ->all();

        return $this->references->map($content, function (string $value) use ($media, $calendar, $accessId, $expiresAt): ?string {
            if (OrganizerCalendarImportService::isRemoteUrl($value)) {
                return $value;
            }

            $mediaId = OrganizerCalendarMediaReferences::mediaIdFromReference($value);

            if ($mediaId === null || ! isset($media[$mediaId])) {
                return null;
            }

            return $this->urlGenerator->generate($calendar->getSlug(), $media[$mediaId], $accessId, $expiresAt);
        });
    }
}

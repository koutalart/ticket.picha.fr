<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\OrganizerCalendar;

use HiEvents\DomainObjects\Generated\OrganizerCalendarMediaDomainObjectAbstract as Media;
use HiEvents\DomainObjects\OrganizerCalendarMediaDomainObject;
use HiEvents\Exceptions\OrganizerCalendarAccessDeniedException;
use HiEvents\Exceptions\OrganizerCalendarNotFoundException;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Repository\Interfaces\OrganizerCalendarMediaRepositoryInterface;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarAccessService;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarMediaStreamer;
use HiEvents\Services\Domain\OrganizerCalendar\OrganizerCalendarResolver;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signed URL proves the access was granted; the access must also still be active (not revoked).
 */
class DownloadOrganizerCalendarMediaHandler
{
    public function __construct(
        private readonly OrganizerCalendarResolver $calendarResolver,
        private readonly OrganizerCalendarMediaRepositoryInterface $mediaRepository,
        private readonly OrganizerCalendarAccessService $accessService,
        private readonly OrganizerCalendarMediaStreamer $streamer,
    ) {}

    /**
     * @throws OrganizerCalendarNotFoundException
     * @throws OrganizerCalendarAccessDeniedException
     */
    public function handle(string $slug, int $mediaId, int $accessId, int $expiresTimestamp): Response
    {
        $calendar = $this->calendarResolver->findBySlug($slug);

        if (! $this->accessService->isActiveById($accessId, $calendar->getId())) {
            throw new OrganizerCalendarAccessDeniedException(
                __('Your access has expired or is invalid. Request a new code.', [], CustomerLocale::get())
            );
        }

        $media = $this->mediaRepository->findFirstWhere([
            Media::ID => $mediaId,
            Media::ORGANIZER_CALENDAR_ID => $calendar->getId(),
        ]);

        if (! $media instanceof OrganizerCalendarMediaDomainObject) {
            throw new OrganizerCalendarNotFoundException('Calendar media not found');
        }

        return $this->streamer->response($media, Carbon::createFromTimestamp($expiresTimestamp));
    }
}

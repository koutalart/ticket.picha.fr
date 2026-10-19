<?php

namespace HiEvents\Services\Domain\Organizer;

use Carbon\Carbon;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;

class CustomDomainCurrentEventService
{
    public const SITE_CONTENT_FLAG = 'redirect_to_current_event';

    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
    ) {}

    public function findEventToRedirectTo(OrganizerDomainObject $organizer): ?EventDomainObject
    {
        $siteContent = $organizer->getSiteContent();
        if (! is_array($siteContent) || empty($siteContent[self::SITE_CONTENT_FLAG])) {
            return null;
        }

        $now = Carbon::now('UTC')->toDateTimeString();

        return $this->eventRepository->findWhere(
            where: [
                EventDomainObjectAbstract::ORGANIZER_ID => $organizer->getId(),
                EventDomainObjectAbstract::STATUS => EventStatus::LIVE->name,
                static fn ($query) => $query
                    ->where(EventDomainObjectAbstract::END_DATE, '>=', $now)
                    ->orWhere(fn ($inner) => $inner
                        ->whereNull(EventDomainObjectAbstract::END_DATE)
                        ->where(EventDomainObjectAbstract::START_DATE, '>=', $now)),
            ],
            orderAndDirections: [new OrderAndDirection(EventDomainObjectAbstract::START_DATE)],
        )->first();
    }
}

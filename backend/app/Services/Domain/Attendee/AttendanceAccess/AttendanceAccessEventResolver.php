<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Attendee\AttendanceAccess;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\Exceptions\AttendanceResponseNotAllowedException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;

class AttendanceAccessEventResolver
{
    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly CustomerLocaleResolver $customerLocaleResolver,
    ) {}

    /**
     * @throws AttendanceResponseNotAllowedException
     */
    public function resolve(int $eventId): EventDomainObject
    {
        /** @var EventDomainObject|null $event */
        $event = $this->eventRepository
            ->loadRelation(new Relationship(EventSettingDomainObject::class))
            ->findFirstWhere(['id' => $eventId]);

        if ($event === null || ! $event->getEventSettings()?->getAllowAttendeeAttendanceResponse()) {
            throw new AttendanceResponseNotAllowedException(
                __('The organizer has not enabled attendance confirmation for this event.', [], $this->customerLocaleResolver->forEvent($event))
            );
        }

        return $event;
    }
}

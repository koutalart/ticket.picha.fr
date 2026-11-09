<?php

namespace HiEvents\Services\Application\Handlers\Attendee;

use HiEvents\Exceptions\AttendanceResponseNotAllowedException;
use HiEvents\Exceptions\InvalidAttendanceCodeException;
use HiEvents\Services\Application\Handlers\Attendee\DTO\AttendeeAttendancePublicDTO;
use HiEvents\Services\Application\Handlers\Attendee\DTO\AttendeeAttendanceStatusDTO;
use HiEvents\Services\Domain\Attendee\AttendeeAttendanceService;
use HiEvents\Services\Domain\Attendee\AttendeeGuestService;

class GetAttendeeAttendancePublicHandler
{
    public function __construct(
        private readonly AttendeeAttendanceService $attendeeAttendanceService,
        private readonly AttendeeGuestService      $attendeeGuestService,
    )
    {
    }

    /**
     * @throws InvalidAttendanceCodeException
     * @throws AttendanceResponseNotAllowedException
     */
    public function handle(AttendeeAttendancePublicDTO $data): AttendeeAttendanceStatusDTO
    {
        $context = $this->attendeeAttendanceService->getContext(
            eventId: $data->eventId,
            attendeeShortId: $data->attendeeShortId,
            code: $data->code,
        );

        return new AttendeeAttendanceStatusDTO(
            attendee: $context->attendee,
            blockingReason: $this->attendeeAttendanceService->getBlockingReason($context),
            guestAvailability: $this->attendeeGuestService->getAvailability($context),
        );
    }
}

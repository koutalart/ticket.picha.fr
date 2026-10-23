<?php

namespace HiEvents\Services\Application\Handlers\Attendee;

use HiEvents\Exceptions\AttendanceResponseNotAllowedException;
use HiEvents\Exceptions\InvalidAttendanceCodeException;
use HiEvents\Services\Application\Handlers\Attendee\DTO\AttendeeAttendancePublicDTO;
use HiEvents\Services\Application\Handlers\Attendee\DTO\AttendeeAttendanceStatusDTO;
use HiEvents\Services\Domain\Attendee\AttendeeAttendanceService;

class ConfirmAttendeeAttendancePublicHandler
{
    public function __construct(
        private readonly AttendeeAttendanceService $attendeeAttendanceService,
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

        $attendee = $this->attendeeAttendanceService->confirm($context);

        return new AttendeeAttendanceStatusDTO(
            attendee: $attendee,
            blockingReason: null,
        );
    }
}

<?php

namespace HiEvents\Services\Application\Handlers\Attendee;

use HiEvents\Exceptions\AttendanceResponseNotAllowedException;
use HiEvents\Exceptions\InvalidAttendanceCodeException;
use HiEvents\Services\Application\Handlers\Attendee\DTO\AttendeeAttendancePublicDTO;
use HiEvents\Services\Application\Handlers\Attendee\DTO\AttendeeAttendanceStatusDTO;
use HiEvents\Services\Domain\Attendee\AttendeeAttendanceService;
use Illuminate\Database\DatabaseManager;
use Throwable;

class CancelAttendeeAttendancePublicHandler
{
    public function __construct(
        private readonly AttendeeAttendanceService $attendeeAttendanceService,
        private readonly DatabaseManager           $databaseManager,
    )
    {
    }

    /**
     * @throws InvalidAttendanceCodeException
     * @throws AttendanceResponseNotAllowedException
     * @throws Throwable
     */
    public function handle(AttendeeAttendancePublicDTO $data): AttendeeAttendanceStatusDTO
    {
        $context = $this->attendeeAttendanceService->getContext(
            eventId: $data->eventId,
            attendeeShortId: $data->attendeeShortId,
            code: $data->code,
        );

        $attendee = $this->databaseManager->transaction(
            fn() => $this->attendeeAttendanceService->cancel($context)
        );

        return new AttendeeAttendanceStatusDTO(
            attendee: $attendee,
            blockingReason: null,
        );
    }
}

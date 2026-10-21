<?php

namespace HiEvents\Resources\Attendee;

use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Services\Application\Handlers\Attendee\DTO\AttendeeAttendanceStatusDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property AttendeeAttendanceStatusDTO $resource
 */
class AttendeeAttendanceResourcePublic extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attendee = $this->resource->attendee;

        return [
            'short_id' => $attendee->getShortId(),
            'first_name' => $attendee->getFirstName(),
            'last_name' => $attendee->getLastName(),
            'status' => $attendee->getStatus(),
            'product_title' => $attendee->getProduct()?->getTitle(),
            'attendance_confirmed_at' => $attendee->getAttendanceConfirmedAt(),
            'cancelled_by_attendee_at' => $attendee->getCancelledByAttendeeAt(),
            'can_respond' => $this->resource->blockingReason === null
                && $attendee->getStatus() === AttendeeStatus::ACTIVE->name,
            'blocking_reason' => $this->resource->blockingReason,
        ];
    }
}

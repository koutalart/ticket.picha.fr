<?php

namespace HiEvents\Resources\CheckInList;

use HiEvents\DomainObjects\AttendeeCheckInDomainObject;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AttendeeCheckInDomainObject
 */
class AttendeeCheckInPublicResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->getId(),
            'short_id' => $this->getShortId(),
            'check_in_list_id' => $this->getCheckInListId(),
            'attendee_id' => $this->getAttendeeId(),
            'checked_in_at' => $this->getCreatedAt(),
            'order_id' => $this->getOrderId(),
            $this->mergeWhen($this->getAttendee() !== null, fn () => [
                'attendee' => [
                    'public_id' => $this->getAttendee()->getPublicId(),
                    'first_name' => $this->getAttendee()->getFirstName(),
                    'last_name' => $this->getAttendee()->getLastName(),
                ],
            ]),
        ];
    }
}

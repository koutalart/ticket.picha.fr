<?php

namespace HiEvents\Resources\Attendee;

use Illuminate\Http\Request;

/**
 * The attendee behind a ticket link. Anyone holding the link (forwarded SMS, screenshot, link preview) can read it,
 * so it carries no contact detail of the buyer.
 */
class AttendeeTicketResourcePublic extends AttendeeResourcePublic
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        unset($data['email']);

        return $data;
    }
}

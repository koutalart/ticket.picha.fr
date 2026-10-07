<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class TicketContentDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $public_id,
        public readonly string $event_title,
        public readonly string $product_title,
        public readonly string $attendee_name,
        public readonly string $event_when,
        public readonly string $event_hours,
        public readonly string $venue_name,
        public readonly string $venue_city,
        public readonly string $organizer_name,
        public readonly string $sponsor_name,
        public readonly ?string $event_logo_image,
        public readonly ?string $sponsor_logo_image,
    ) {}
}

<?php

namespace HiEvents\Services\Application\Handlers\DemoRequest\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\DemoRequestEventType;

class SendDemoRequestDTO extends BaseDataObject
{
    public function __construct(
        public string               $first_name,
        public string               $last_name,
        public string               $email,
        public string               $organization,
        public DemoRequestEventType $event_type,
        public ?string              $event_date,
        public string               $attendee_count,
    )
    {
    }
}

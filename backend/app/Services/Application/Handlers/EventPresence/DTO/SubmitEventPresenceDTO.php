<?php

namespace HiEvents\Services\Application\Handlers\EventPresence\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SubmitEventPresenceDTO extends BaseDataObject
{
    public function __construct(
        public int $event_id,
        public string $first_name,
        public string $phone,
        public ?string $last_name = null,
        public ?string $staff = null,
        public ?string $ip = null,
    ) {}
}

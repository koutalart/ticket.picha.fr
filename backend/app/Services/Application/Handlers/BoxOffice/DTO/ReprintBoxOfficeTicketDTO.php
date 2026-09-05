<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class ReprintBoxOfficeTicketDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $attendee_id,
        public readonly int $event_id,
        public readonly int $agent_user_id,
    )
    {
    }
}

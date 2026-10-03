<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\Services\Domain\Ticket\DTO\ZplLabelFormatDTO;

class GetBoxOfficeTicketZplDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $event_id,
        public readonly string $attendee_public_id,
        public readonly int $agent_user_id,
        public readonly ?ZplLabelFormatDTO $label_format = null,
    ) {}
}

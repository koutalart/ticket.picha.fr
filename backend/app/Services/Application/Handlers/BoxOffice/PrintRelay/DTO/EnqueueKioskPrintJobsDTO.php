<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\Services\Domain\Ticket\DTO\ZplLabelFormatDTO;

class EnqueueKioskPrintJobsDTO extends BaseDataObject
{
    /**
     * @param  string[]  $attendee_public_ids
     */
    public function __construct(
        public readonly int $event_id,
        public readonly array $attendee_public_ids,
        public readonly int $user_id,
        public readonly ?ZplLabelFormatDTO $label_format = null,
    ) {}
}

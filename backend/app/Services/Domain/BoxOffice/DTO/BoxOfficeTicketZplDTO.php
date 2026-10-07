<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\BoxOffice\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class BoxOfficeTicketZplDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $attendee_id,
        public readonly string $zpl,
    ) {}
}

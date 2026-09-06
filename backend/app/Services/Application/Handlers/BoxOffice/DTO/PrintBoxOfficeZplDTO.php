<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class PrintBoxOfficeZplDTO extends BaseDataObject
{
    public function __construct(
        public readonly int    $event_id,
        public readonly string $attendee_public_id,
        public readonly int    $agent_user_id,
        public readonly string $printer_host,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\EventAssistant\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

/**
 * What the assistant read on a poster. Dates are local to the organizer's timezone ("Y-m-d\TH:i").
 */
class EventDraftDTO extends BaseDataObject
{
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $start_date = null,
        public readonly ?string $end_date = null,
        public readonly ?string $venue = null,
        public readonly ?string $description = null,
        public readonly ?float $price = null,
    ) {}
}

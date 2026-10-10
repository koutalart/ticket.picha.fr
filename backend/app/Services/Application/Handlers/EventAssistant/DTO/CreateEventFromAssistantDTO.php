<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\EventAssistant\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class CreateEventFromAssistantDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $account_id,
        public readonly int $user_id,
        public readonly int $organizer_id,
        public readonly string $title,
        public readonly string $start_date,
        public readonly ?string $end_date,
        public readonly ?string $venue,
        public readonly ?string $description,
        public readonly float $price,
        public readonly ?int $quantity,
        public readonly ?string $category = null,
    ) {}
}

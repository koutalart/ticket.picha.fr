<?php

namespace HiEvents\Services\Application\Handlers\Contact\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class ContactTimelineItemDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $type,
        public readonly string $occurred_at,
        public readonly ?int $event_id,
        public readonly ?string $event_title,
        public readonly ?int $order_id,
        public readonly ?string $title,
        public readonly ?string $detail,
        public readonly ?float $amount = null,
        public readonly ?string $currency = null,
        public readonly ?string $status = null,
    ) {}
}

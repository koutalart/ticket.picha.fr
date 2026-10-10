<?php

namespace HiEvents\Services\Application\Handlers\CaseStudy\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class CaseStudyEventStatsDTO extends BaseDataObject
{
    public function __construct(
        public int $id,
        public string $title,
        public string $status,
        public ?string $category,
        public string $timezone,
        public ?string $start_local,
        public ?string $end_local,
        public string $organizer,
        public ?array $location,
        public ?string $first_sale_utc,
        public int $tickets_active,
        public int $orders_completed,
        public array $tickets_by_channel_product,
        public array $tickets_by_day,
        public array $prices,
        public int $checked_in,
        public array $check_ins_by_hour,
    ) {}
}

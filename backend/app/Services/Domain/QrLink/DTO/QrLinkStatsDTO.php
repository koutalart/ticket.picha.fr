<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\QrLink\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class QrLinkStatsDTO extends BaseDataObject
{
    public function __construct(
        public int $qr_link_id,
        public ?int $days,
        public string $timezone,
        public int $scans,
        public int $unique_scans,
        public int $clicks,
        public int $orders,
        public float $revenue_gross,
        public ?float $conversion_rate,
        public array $daily,
        public array $hours,
        public array $devices,
        public array $operating_systems,
        public array $browsers,
        public array $countries,
        public array $referrers,
        public array $clicks_by_target,
    ) {}
}

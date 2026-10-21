<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsCampaignStatsDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $recipients = 0,
        public readonly int $pending = 0,
        public readonly int $sent = 0,
        public readonly int $delivered = 0,
        public readonly int $failed = 0,
        public readonly int $visits = 0,
        public readonly int $clickers = 0,
        public readonly int $orders = 0,
        public readonly float $revenueGross = 0.0,
    ) {}
}

<?php

namespace HiEvents\Services\Application\Handlers\Order\DTO;

use HiEvents\DataTransferObjects\BaseDTO;

class RefundOrderDTO extends BaseDTO
{
    public function __construct(
        public readonly int $event_id,
        public readonly int $order_id,
        public readonly float $amount,
        public readonly bool $notify_buyer,
        public readonly bool $cancel_order,
        /** @var int[] tickets refunded, cancelled (PICHA: a refunded ticket is invalid) */
        public readonly array $attendee_ids = [],
    ) {}
}

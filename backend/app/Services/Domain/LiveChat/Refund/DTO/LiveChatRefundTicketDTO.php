<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\Refund\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

/**
 * A ticket of the order that the web chat can cancel, with the price refunded for it (the ticket price only).
 */
class LiveChatRefundTicketDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $attendeeId,
        public readonly string $name,
        public readonly string $category,
        public readonly float $amount,
    ) {}
}

<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\Refund\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use Illuminate\Support\Collection;

/**
 * What the cancellation protection of an order covers: the active tickets that can be refunded, and how many of them
 * at most (one per protection bought when the protection is sold per ticket).
 */
class LiveChatRefundCoverageDTO extends BaseDataObject
{
    /**
     * @param  Collection<int, LiveChatRefundTicketDTO>  $tickets
     */
    public function __construct(
        public readonly bool $hasProtection,
        public readonly Collection $tickets,
        public readonly int $maxTickets,
    ) {}
}

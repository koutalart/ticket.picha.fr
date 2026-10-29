<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\TicketResend\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

/**
 * An order of the organizer one of whose phones ends like the number given in the chat.
 */
class LiveChatTicketResendCandidateDTO extends BaseDataObject
{
    /**
     * @param  list<string>  $phones  phones given with the order (order or attendee answer, box office sale), as typed
     * @param  list<string>  $firstNames  first names of the buyer and of the attendees
     */
    public function __construct(
        public readonly int $orderId,
        public readonly int $eventId,
        public readonly ?string $email,
        public readonly string $status,
        public readonly ?string $paymentStatus,
        public readonly array $phones,
        public readonly array $firstNames,
    ) {}
}

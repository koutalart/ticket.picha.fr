<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Email\Inbox\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class OutgoingEmailContextDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $organizerId,
        public readonly int $accountId,
        public readonly ?int $eventId = null,
        public readonly ?int $orderId = null,
        public readonly ?int $attendeeId = null,
        public readonly ?string $contactName = null,
    ) {}
}

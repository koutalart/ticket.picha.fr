<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class ZplTicketDataDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $publicId,
        public readonly string $eventTitle,
        public readonly string $productTitle,
        public readonly string $attendeeName = '',
        public readonly string $eventDate = '',
        public readonly string $eventTime = '',
        public readonly string $venue = '',
        public readonly string $sellerName = '',
        public readonly ?string $organizerLogo = null,
        public readonly ?string $sponsorLogo = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsCustomerOrderDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $publicId,
        public readonly int $eventId,
        public readonly string $eventTitle,
        public readonly ?string $eventStartDate,
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        public readonly ?string $email,
        public readonly float $totalGross,
        public readonly string $currency,
        public readonly string $status,
        public readonly string $createdAt,
    ) {}
}

<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsCustomerContactDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $organizerId,
        public readonly string $phone,
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        public readonly ?string $email,
        public readonly string $orderedAt,
    ) {}
}

<?php

namespace HiEvents\Services\Application\Handlers\Contact\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class UpdateContactDTO extends BaseDataObject
{
    public function __construct(
        public readonly int     $organizerId,
        public readonly int     $contactId,
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        public readonly ?string $phone,
        public readonly bool    $emailConsent,
        public readonly bool    $smsConsent,
    )
    {
    }
}

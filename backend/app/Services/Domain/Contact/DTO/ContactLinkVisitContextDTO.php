<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Contact\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class ContactLinkVisitContextDTO extends BaseDataObject
{
    public function __construct(
        public ?string $ipAddress,
        public ?string $userAgent,
        public ?string $referrer = null,
        public ?string $countryCode = null,
    ) {}
}

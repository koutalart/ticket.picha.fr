<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\QrLink\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\QrLinkVisitType;

class QrLinkVisitContextDTO extends BaseDataObject
{
    public function __construct(
        public QrLinkVisitType $type,
        public ?string $ipAddress,
        public ?string $userAgent,
        public ?string $target = null,
        public ?string $referrer = null,
        public ?string $countryCode = null,
    ) {}
}

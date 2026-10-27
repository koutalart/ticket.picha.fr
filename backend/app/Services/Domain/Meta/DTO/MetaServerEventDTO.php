<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Meta\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class MetaServerEventDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $eventId,
        public readonly string $eventName,
        public readonly string $metaEventId,
        public readonly int $eventTime,
        public readonly bool $consent,
        public readonly ?string $eventSourceUrl = null,
        public readonly ?string $clientIpAddress = null,
        public readonly ?string $clientUserAgent = null,
        public readonly ?string $fbp = null,
        public readonly ?string $fbc = null,
        public readonly ?float $value = null,
        public readonly ?string $currency = null,
        public readonly ?int $numItems = null,
    ) {}
}

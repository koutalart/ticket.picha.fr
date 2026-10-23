<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\SmsWatchdogAnomalyType;

class SmsWatchdogAnomalyDTO extends BaseDataObject
{
    public function __construct(
        public readonly SmsWatchdogAnomalyType $type,
        public readonly string $key,
        public readonly int $organizerId,
        public readonly string $organizerName,
        public readonly ?int $conversationId = null,
        public readonly ?string $phone = null,
        public readonly ?string $occurredAt = null,
        public readonly ?int $count = null,
        public readonly ?int $eventId = null,
        public readonly ?string $excerpt = null,
        public readonly ?int $thresholdMinutes = null,
    ) {}
}

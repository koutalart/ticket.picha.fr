<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\SmsFile\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\SmsFilePriority;

class SmsFileMessageDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $organizerId,
        public readonly string $from,
        public readonly string $to,
        public readonly string $text,
        public readonly SmsFilePriority $priority,
        public readonly string $ref,
        public readonly ?string $campaign = null,
    ) {}

    public function payload(): array
    {
        return array_filter([
            'organisateur' => $this->organizerId,
            'from' => $this->from,
            'to' => $this->to,
            'texte' => $this->text,
            'priorite' => $this->priority->value,
            'campagne' => $this->campaign,
            'ref' => $this->ref,
        ], fn ($value) => $value !== null);
    }
}

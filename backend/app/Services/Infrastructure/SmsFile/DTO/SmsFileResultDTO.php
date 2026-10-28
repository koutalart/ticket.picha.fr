<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\SmsFile\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\SmsFileState;

class SmsFileResultDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $id,
        public readonly SmsFileState $state,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $ref = null,
        public readonly ?string $reason = null,
        public readonly bool $duplicate = false,
    ) {}

    public static function fromResponse(array $row): ?self
    {
        $state = SmsFileState::tryFrom((string) ($row['etat'] ?? ''));
        if (! is_numeric($row['id'] ?? null) || $state === null) {
            return null;
        }

        $providerId = $row['id_sms_picha'] ?? null;

        return new self(
            id: (int) $row['id'],
            state: $state,
            providerMessageId: is_scalar($providerId) && (string) $providerId !== '' ? (string) $providerId : null,
            ref: isset($row['ref']) && is_scalar($row['ref']) ? (string) $row['ref'] : null,
            reason: isset($row['raison']) && is_scalar($row['raison']) ? mb_substr((string) $row['raison'], 0, 500) : null,
            duplicate: (bool) ($row['doublon'] ?? false),
        );
    }
}

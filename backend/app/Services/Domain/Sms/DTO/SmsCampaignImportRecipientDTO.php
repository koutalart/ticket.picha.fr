<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsCampaignImportRecipientDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $phone,
        public readonly ?string $contactName = null,
        public readonly ?string $shortId = null,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $status = null,
        public readonly ?string $failureReason = null,
        public readonly ?string $scheduledAt = null,
    ) {}
}

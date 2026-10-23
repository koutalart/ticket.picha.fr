<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsCampaignImportDTO extends BaseDataObject
{
    /**
     * @param  SmsCampaignImportRecipientDTO[]  $recipients
     */
    public function __construct(
        public readonly int $organizerId,
        public readonly string $name,
        public readonly array $recipients,
        public readonly ?int $eventId = null,
        public readonly ?string $message = null,
        public readonly ?int $retireQrCampaignId = null,
        public readonly ?string $linkBaseUrl = null,
    ) {}
}

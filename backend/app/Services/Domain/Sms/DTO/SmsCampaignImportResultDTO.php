<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsCampaignImportResultDTO extends BaseDataObject
{
    /**
     * @param  string[]  $warnings
     */
    public function __construct(
        public readonly int $campaignId,
        public readonly int $created,
        public readonly int $updated,
        public readonly int $linkedQrLinks,
        public readonly array $warnings,
        public readonly bool $qrCampaignRetired,
    ) {}
}

<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsCampaignRecipientMessageDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $phone,
        public readonly ?string $contactName,
        public readonly string $link,
        public readonly string $message,
    ) {}
}

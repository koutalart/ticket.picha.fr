<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Contact\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsRecipientContactLinkResultDTO extends BaseDataObject
{
    public function __construct(
        public int $linkedQrLinks,
        public int $createdContacts,
        public int $skippedQrLinks,
    ) {}
}

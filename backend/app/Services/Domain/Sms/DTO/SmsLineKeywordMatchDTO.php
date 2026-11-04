<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;

class SmsLineKeywordMatchDTO extends BaseDataObject
{
    public function __construct(
        public readonly OrganizerSmsSettingDomainObject $settings,
        public readonly string $keyword,
    ) {}
}

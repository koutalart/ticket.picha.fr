<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;

class OrganizerSmsSettingsViewDTO extends BaseDataObject
{
    public function __construct(
        public readonly OrganizerSmsSettingDomainObject $settings,
        public readonly string $webhookUrl,
        public readonly bool $assistantAvailable,
        public readonly ?OrganizerSmsSettingDomainObject $sharedLine = null,
    ) {}
}

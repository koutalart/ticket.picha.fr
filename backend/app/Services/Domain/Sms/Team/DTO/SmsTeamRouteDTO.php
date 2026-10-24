<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\Team\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\OrganizerTeamContactDomainObject;

class SmsTeamRouteDTO extends BaseDataObject
{
    public function __construct(
        public readonly OrganizerSmsSettingDomainObject $settings,
        public readonly OrganizerTeamContactDomainObject $contact,
    ) {}
}

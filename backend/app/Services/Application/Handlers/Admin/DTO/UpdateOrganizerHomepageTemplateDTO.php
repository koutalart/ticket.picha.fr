<?php

namespace HiEvents\Services\Application\Handlers\Admin\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\OrganizerHomepageTemplate;

class UpdateOrganizerHomepageTemplateDTO extends BaseDataObject
{
    public function __construct(
        public readonly int                       $accountId,
        public readonly int                       $organizerId,
        public readonly OrganizerHomepageTemplate $homepageTemplate,
    )
    {
    }
}

<?php

namespace HiEvents\Services\Application\Handlers\Admin\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class UpdateOrganizerSiteContentDTO extends BaseDataObject
{
    public function __construct(
        public readonly int   $accountId,
        public readonly int   $organizerId,
        public readonly array $siteContent,
    )
    {
    }
}

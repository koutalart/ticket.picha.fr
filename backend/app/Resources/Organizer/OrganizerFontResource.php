<?php

namespace HiEvents\Resources\Organizer;

use HiEvents\DomainObjects\OrganizerFontDomainObject;
use HiEvents\Helper\Url;
use HiEvents\Resources\BaseResource;

/**
 * @mixin OrganizerFontDomainObject
 */
class OrganizerFontResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->getId(),
            'organizer_id' => $this->getOrganizerId(),
            'family_name' => $this->getFamilyName(),
            'weight' => $this->getWeight(),
            'format' => $this->getFormat(),
            'file_name' => $this->getFilename(),
            'size' => $this->getSize(),
            'url' => Url::getCdnUrl($this->getPath()),
            'updated_at' => $this->getUpdatedAt(),
        ];
    }
}

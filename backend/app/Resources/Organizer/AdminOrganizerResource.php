<?php

namespace HiEvents\Resources\Organizer;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin OrganizerDomainObject
 */
class AdminOrganizerResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'slug' => $this->getSlug(),
            'status' => $this->getStatus(),
            'custom_domain' => $this->getCustomDomain(),
            'homepage_template' => $this->getHomepageTemplate(),
            'site_content' => $this->getSiteContent(),
        ];
    }
}

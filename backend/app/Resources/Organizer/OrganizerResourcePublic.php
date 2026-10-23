<?php

namespace HiEvents\Resources\Organizer;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerFontDomainObject;
use HiEvents\Helper\Url;
use HiEvents\Resources\Event\EventResourcePublic;
use HiEvents\Services\Domain\PublicUrl\PublicBaseUrlResolver;
use HiEvents\Services\Domain\Organizer\Font\OrganizerFontService;
use HiEvents\Resources\Image\ImageResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrganizerDomainObject
 */
class OrganizerResourcePublic extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'website' => $this->getWebsite(),
            'description' => $this->getDescription(),
            'slug' => $this->getSlug(),
            'status' => $this->getStatus(),
            'homepage_template' => $this->getHomepageTemplate(),
            'site_content' => $this->getSiteContent(),
            'custom_domain' => PublicBaseUrlResolver::activeCustomDomain($this->resource),
            'images' => $this->when(
                (bool)$this->getImages(),
                fn() => ImageResource::collection($this->getImages())
            ),
            'events' => $this->when(
                condition: !is_null($this->getEvents()),
                value: fn() => EventResourcePublic::collection($this->getEvents())
            ),
            'settings' => $this->when(
                condition: !is_null($this->getOrganizerSettings()),
                value: fn() => new OrganizerSettingsPublicResource($this->getOrganizerSettings())
            ),
            'custom_font' => $this->when(
                condition: !is_null($this->getOrganizerFonts()),
                value: fn() => $this->buildCustomFont(),
            ),
        ];
    }

    private function buildCustomFont(): ?array
    {
        $fonts = $this->getOrganizerFonts();

        if (!OrganizerFontService::hasRegularWeight($fonts)) {
            return null;
        }

        $sorted = $fonts->sortBy(fn(OrganizerFontDomainObject $font) => $font->getWeight())->values();

        return [
            'family_name' => $sorted->first()->getFamilyName(),
            'files' => $sorted->map(fn(OrganizerFontDomainObject $font) => [
                'weight' => $font->getWeight(),
                'format' => $font->getFormat(),
                'url' => Url::getCdnUrl($font->getPath()),
            ])->all(),
        ];
    }
}

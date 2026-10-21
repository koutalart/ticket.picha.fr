<?php

namespace HiEvents\Services\Application\Handlers\Organizer\Font;

use HiEvents\DomainObjects\OrganizerFontDomainObject;
use HiEvents\Services\Domain\Organizer\Font\OrganizerFontService;
use Illuminate\Support\Collection;

class GetOrganizerFontsHandler
{
    public function __construct(
        private readonly OrganizerFontService $organizerFontService,
    ) {}

    /**
     * @return Collection<OrganizerFontDomainObject>
     */
    public function handle(int $organizerId): Collection
    {
        return $this->organizerFontService->getFonts($organizerId);
    }
}

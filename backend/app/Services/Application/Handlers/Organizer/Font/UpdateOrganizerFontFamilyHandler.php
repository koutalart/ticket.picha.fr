<?php

namespace HiEvents\Services\Application\Handlers\Organizer\Font;

use HiEvents\DomainObjects\OrganizerFontDomainObject;
use HiEvents\Services\Domain\Organizer\Font\OrganizerFontService;
use Illuminate\Support\Collection;

class UpdateOrganizerFontFamilyHandler
{
    public function __construct(
        private readonly OrganizerFontService $organizerFontService,
    ) {}

    /**
     * @return Collection<OrganizerFontDomainObject>
     */
    public function handle(int $organizerId, string $familyName): Collection
    {
        return $this->organizerFontService->renameFamily($organizerId, trim($familyName));
    }
}

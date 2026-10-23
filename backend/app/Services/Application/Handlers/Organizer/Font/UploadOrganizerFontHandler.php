<?php

namespace HiEvents\Services\Application\Handlers\Organizer\Font;

use HiEvents\DomainObjects\OrganizerFontDomainObject;
use HiEvents\Exceptions\InvalidOrganizerFontException;
use HiEvents\Services\Application\Handlers\Organizer\Font\DTO\UploadOrganizerFontDTO;
use HiEvents\Services\Domain\Organizer\Font\OrganizerFontService;

class UploadOrganizerFontHandler
{
    public function __construct(
        private readonly OrganizerFontService $organizerFontService,
    ) {}

    /**
     * @throws InvalidOrganizerFontException
     */
    public function handle(UploadOrganizerFontDTO $dto): OrganizerFontDomainObject
    {
        return $this->organizerFontService->upload(
            organizerId: $dto->organizerId,
            accountId: $dto->accountId,
            familyName: trim($dto->familyName),
            weight: $dto->weight,
            file: $dto->file,
        );
    }
}

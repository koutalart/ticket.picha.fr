<?php

namespace HiEvents\Services\Application\Handlers\Organizer\Font;

use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Services\Domain\Organizer\Font\OrganizerFontService;

class DeleteOrganizerFontHandler
{
    public function __construct(
        private readonly OrganizerFontService $organizerFontService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $organizerId, int $fontId): void
    {
        $this->organizerFontService->delete($organizerId, $fontId);
    }
}

<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms\Team;

use HiEvents\Exceptions\OrganizerTeamContactException;
use HiEvents\Services\Domain\Sms\Team\OrganizerTeamContactService;

class DeleteOrganizerTeamContactHandler
{
    public function __construct(
        private readonly OrganizerTeamContactService $teamContactService,
    ) {}

    /**
     * @throws OrganizerTeamContactException
     */
    public function handle(int $organizerId, int $contactId): void
    {
        $this->teamContactService->remove($organizerId, $contactId);
    }
}

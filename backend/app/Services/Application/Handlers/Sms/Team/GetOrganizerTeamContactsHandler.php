<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms\Team;

use HiEvents\DomainObjects\OrganizerTeamContactDomainObject;
use HiEvents\Services\Domain\Sms\Team\OrganizerTeamContactService;
use Illuminate\Support\Collection;

class GetOrganizerTeamContactsHandler
{
    public function __construct(
        private readonly OrganizerTeamContactService $teamContactService,
    ) {}

    /**
     * @return Collection<int, OrganizerTeamContactDomainObject>
     */
    public function handle(int $organizerId): Collection
    {
        return $this->teamContactService->list($organizerId);
    }
}

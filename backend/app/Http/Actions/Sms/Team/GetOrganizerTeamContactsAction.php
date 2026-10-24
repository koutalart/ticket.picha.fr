<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms\Team;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Sms\OrganizerTeamContactResource;
use HiEvents\Services\Application\Handlers\Sms\Team\GetOrganizerTeamContactsHandler;
use Illuminate\Http\JsonResponse;

class GetOrganizerTeamContactsAction extends BaseAction
{
    public function __construct(
        private readonly GetOrganizerTeamContactsHandler $handler,
    ) {}

    public function __invoke(int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        return $this->resourceResponse(OrganizerTeamContactResource::class, $this->handler->handle($organizerId));
    }
}

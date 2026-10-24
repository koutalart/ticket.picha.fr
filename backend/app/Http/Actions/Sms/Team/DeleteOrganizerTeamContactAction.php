<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms\Team;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\OrganizerTeamContactException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Sms\Team\DeleteOrganizerTeamContactHandler;
use Illuminate\Http\Response;

class DeleteOrganizerTeamContactAction extends BaseAction
{
    public function __construct(
        private readonly DeleteOrganizerTeamContactHandler $handler,
    ) {}

    public function __invoke(int $organizerId, int $contactId): Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $this->handler->handle($organizerId, $contactId);
        } catch (OrganizerTeamContactException) {
            return $this->notFoundResponse();
        }

        return $this->deletedResponse();
    }
}

<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Sms\OrganizerSmsSettingsResource;
use HiEvents\Services\Application\Handlers\Sms\GetOrganizerSmsSettingsHandler;
use Illuminate\Http\JsonResponse;

class GetOrganizerSmsSettingsAction extends BaseAction
{
    public function __construct(
        private readonly GetOrganizerSmsSettingsHandler $handler,
    ) {}

    public function __invoke(int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        return $this->resourceResponse(
            OrganizerSmsSettingsResource::class,
            $this->handler->handle($this->getAuthenticatedAccountId(), $organizerId),
        );
    }
}

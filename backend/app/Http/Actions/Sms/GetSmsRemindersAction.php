<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Sms\SmsCampaignResource;
use HiEvents\Services\Application\Handlers\Sms\GetSmsRemindersHandler;
use Illuminate\Http\JsonResponse;

class GetSmsRemindersAction extends BaseAction
{
    public function __construct(
        private readonly GetSmsRemindersHandler $handler,
    ) {}

    public function __invoke(int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        return $this->resourceResponse(SmsCampaignResource::class, $this->handler->handle($organizerId));
    }
}

<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\EventAssistant;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Domain\EventAssistant\EventAssistantService;
use Illuminate\Http\JsonResponse;

class GetEventAssistantStatusAction extends BaseAction
{
    public function __construct(
        private readonly EventAssistantService $eventAssistantService,
    ) {}

    public function __invoke(): JsonResponse
    {
        $this->minimumAllowedRole(Role::ORGANIZER);

        return $this->jsonResponse(['ai_enabled' => $this->eventAssistantService->isAvailable()], wrapInData: true);
    }
}

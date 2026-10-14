<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\EventAssistant;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Exceptions\EventAssistantFailedException;
use HiEvents\Exceptions\EventAssistantUnavailableException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\EventAssistant\ResearchEventDescriptionRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Domain\EventAssistant\EventAssistantService;
use Illuminate\Http\JsonResponse;

class ResearchEventDescriptionAction extends BaseAction
{
    public function __construct(
        private readonly EventAssistantService $eventAssistantService,
    ) {}

    public function __invoke(ResearchEventDescriptionRequest $request): JsonResponse
    {
        $this->minimumAllowedRole(Role::ORGANIZER);

        try {
            $description = $this->eventAssistantService->researchDescription(
                $request->validated('title'),
                $request->validated('venue'),
                $request->validated('category'),
                $request->validated('facts'),
                $request->validated('draft'),
            );
        } catch (EventAssistantUnavailableException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_SERVICE_UNAVAILABLE);
        } catch (EventAssistantFailedException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->jsonResponse(['description' => $description], wrapInData: true);
    }
}

<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\EventAssistant;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Exceptions\EventAssistantFailedException;
use HiEvents\Exceptions\EventAssistantUnavailableException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\EventAssistant\ReadEventPosterRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Domain\EventAssistant\EventAssistantService;
use Illuminate\Http\JsonResponse;

class ReadEventPosterAction extends BaseAction
{
    public function __construct(
        private readonly EventAssistantService $eventAssistantService,
    ) {}

    public function __invoke(ReadEventPosterRequest $request): JsonResponse
    {
        $this->minimumAllowedRole(Role::ORGANIZER);

        try {
            $draft = $this->eventAssistantService->readPoster(
                $request->file('image'),
                $request->validated('timezone') ?? config('app.timezone'),
            );
        } catch (EventAssistantUnavailableException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_SERVICE_UNAVAILABLE);
        } catch (EventAssistantFailedException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->jsonResponse($draft->toArray(), wrapInData: true);
    }
}

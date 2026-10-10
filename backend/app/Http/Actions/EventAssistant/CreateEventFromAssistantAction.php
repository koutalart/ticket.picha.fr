<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\EventAssistant;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Exceptions\OrganizerNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\EventAssistant\CreateEventFromAssistantRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Event\EventResource;
use HiEvents\Services\Application\Handlers\EventAssistant\CreateEventFromAssistantHandler;
use HiEvents\Services\Application\Handlers\EventAssistant\DTO\CreateEventFromAssistantDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateEventFromAssistantAction extends BaseAction
{
    public function __construct(
        private readonly CreateEventFromAssistantHandler $handler,
    ) {}

    /**
     * @throws ValidationException|Throwable
     */
    public function __invoke(CreateEventFromAssistantRequest $request): JsonResponse
    {
        $this->minimumAllowedRole(Role::ORGANIZER);

        try {
            $event = $this->handler->handle(new CreateEventFromAssistantDTO(
                account_id: $this->getAuthenticatedAccountId(),
                user_id: $this->getAuthenticatedUser()->getId(),
                organizer_id: (int) $request->validated('organizer_id'),
                title: $request->validated('title'),
                start_date: $request->validated('start_date'),
                end_date: $request->validated('end_date'),
                venue: $request->validated('venue'),
                description: $request->validated('description'),
                price: (float) $request->validated('price'),
                quantity: $request->validated('quantity') !== null ? (int) $request->validated('quantity') : null,
            ));
        } catch (OrganizerNotFoundException $exception) {
            throw ValidationException::withMessages(['organizer_id' => $exception->getMessage()]);
        }

        return $this->resourceResponse(EventResource::class, $event, ResponseCodes::HTTP_CREATED);
    }
}

<?php

namespace HiEvents\Http\Actions\BoxOffice\Operators;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\BoxOffice\CreateBoxOfficeOperatorRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\BoxOffice\BoxOfficeOperatorResource;
use HiEvents\Services\Application\Handlers\BoxOffice\CreateBoxOfficeOperatorHandler;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\CreateBoxOfficeOperatorDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * D23 (PICHA_KIOSK_V2_DECISIONS.md) — invite/assign a box office operator for
 * an event. ADMIN of the event's account only (an ORGANIZER or an operator
 * is refused by isActionAuthorized / validateUserRole).
 */
class CreateBoxOfficeOperatorAction extends BaseAction
{
    public function __construct(
        private readonly CreateBoxOfficeOperatorHandler $handler,
    ) {}

    public function __invoke(CreateBoxOfficeOperatorRequest $request, int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class, Role::ADMIN);

        try {
            $operator = $this->handler->handle(new CreateBoxOfficeOperatorDTO(
                event_id: $eventId,
                account_id: $this->getAuthenticatedAccountId(),
                created_by_user_id: $this->getAuthenticatedUser()->getId(),
                first_name: $request->validated('first_name'),
                last_name: $request->validated('last_name') ?? '',
                email: $request->validated('email'),
            ));
        } catch (ResourceConflictException $exception) {
            throw ValidationException::withMessages(['email' => $exception->getMessage()]);
        }

        return $this->resourceResponse(
            resource: BoxOfficeOperatorResource::class,
            data: $operator,
            statusCode: ResponseCodes::HTTP_CREATED,
        );
    }
}

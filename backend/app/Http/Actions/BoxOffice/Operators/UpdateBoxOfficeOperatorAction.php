<?php

namespace HiEvents\Http\Actions\BoxOffice\Operators;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\BoxOffice\UpdateBoxOfficeOperatorRequest;
use HiEvents\Resources\BoxOffice\BoxOfficeOperatorResource;
use HiEvents\Services\Application\Handlers\BoxOffice\UpdateBoxOfficeOperatorHandler;
use Illuminate\Http\JsonResponse;

/**
 * D23 (PICHA_KIOSK_V2_DECISIONS.md) — revoke / re-activate an operator for
 * one event. ADMIN only. Per-event: the operator's other assignments are
 * untouched, and the users / account_users rows are kept for audit.
 */
class UpdateBoxOfficeOperatorAction extends BaseAction
{
    public function __construct(
        private readonly UpdateBoxOfficeOperatorHandler $handler,
    ) {}

    public function __invoke(UpdateBoxOfficeOperatorRequest $request, int $eventId, int $userId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class, Role::ADMIN);

        return $this->resourceResponse(
            resource: BoxOfficeOperatorResource::class,
            data: $this->handler->handle($eventId, $userId, $request->validated('status')),
        );
    }
}

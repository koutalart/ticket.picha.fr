<?php

namespace HiEvents\Http\Actions\BoxOffice\Operators;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\BoxOffice\BoxOfficeOperatorResource;
use HiEvents\Services\Application\Handlers\BoxOffice\GetBoxOfficeOperatorsHandler;
use Illuminate\Http\JsonResponse;

class GetBoxOfficeOperatorsAction extends BaseAction
{
    public function __construct(
        private readonly GetBoxOfficeOperatorsHandler $handler,
    ) {}

    public function __invoke(int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class, Role::ADMIN);

        return $this->resourceResponse(
            resource: BoxOfficeOperatorResource::class,
            data: $this->handler->handle($eventId),
        );
    }
}

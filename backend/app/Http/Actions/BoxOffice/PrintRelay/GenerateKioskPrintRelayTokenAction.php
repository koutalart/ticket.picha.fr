<?php

namespace HiEvents\Http\Actions\BoxOffice\PrintRelay;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay\GenerateKioskPrintRelayTokenHandler;
use Illuminate\Http\JsonResponse;

class GenerateKioskPrintRelayTokenAction extends BaseAction
{
    public function __construct(
        private readonly GenerateKioskPrintRelayTokenHandler $handler,
    ) {}

    public function __invoke(int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        return $this->jsonResponse($this->handler->handle($eventId)->toArray(), ResponseCodes::HTTP_CREATED, wrapInData: true);
    }
}

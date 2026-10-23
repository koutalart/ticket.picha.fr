<?php

namespace HiEvents\Http\Actions\BoxOffice\PrintRelay;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay\GetKioskPrintRelayStatusHandler;
use Illuminate\Http\JsonResponse;

class GetKioskPrintRelayStatusAction extends BaseAction
{
    public function __construct(
        private readonly GetKioskPrintRelayStatusHandler $handler,
    ) {}

    public function __invoke(int $eventId): JsonResponse
    {
        $this->isBoxOfficeActionAuthorized($eventId);

        return $this->jsonResponse($this->handler->handle($eventId)->toArray(), wrapInData: true);
    }
}

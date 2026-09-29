<?php

namespace HiEvents\Http\Actions\BoxOffice;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\BoxOffice\GetBoxOfficeStatsHandler;
use Illuminate\Http\JsonResponse;

class GetBoxOfficeStatsAction extends BaseAction
{
    public function __construct(
        private readonly GetBoxOfficeStatsHandler $handler,
    ) {}

    public function __invoke(int $eventId): JsonResponse
    {
        $this->isBoxOfficeActionAuthorized($eventId);

        $stats = $this->handler->handle($eventId, $this->getAuthenticatedUser(), $this->getAuthenticatedUserRole());

        return $this->jsonResponse($stats->toArray(), wrapInData: true);
    }
}

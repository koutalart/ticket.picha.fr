<?php

namespace HiEvents\Http\Actions\BoxOffice;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\BoxOffice\SearchBoxOfficeAttendeesHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchBoxOfficeAttendeesAction extends BaseAction
{
    public function __construct(
        private readonly SearchBoxOfficeAttendeesHandler $handler,
    ) {}

    public function __invoke(Request $request, int $eventId): JsonResponse
    {
        $this->isBoxOfficeActionAuthorized($eventId);

        $results = $this->handler->handle($eventId, (string) $request->query('query', ''));

        return $this->jsonResponse($results->map->toArray()->all(), wrapInData: true);
    }
}

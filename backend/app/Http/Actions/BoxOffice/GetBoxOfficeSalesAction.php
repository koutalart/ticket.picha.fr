<?php

namespace HiEvents\Http\Actions\BoxOffice;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\BoxOffice\GetBoxOfficeSalesHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetBoxOfficeSalesAction extends BaseAction
{
    public function __construct(
        private readonly GetBoxOfficeSalesHandler $handler,
    ) {}

    public function __invoke(Request $request, int $eventId): JsonResponse
    {
        $this->isBoxOfficeActionAuthorized($eventId);

        $page = $this->handler->handle(
            eventId: $eventId,
            user: $this->getAuthenticatedUser(),
            role: $this->getAuthenticatedUserRole(),
            page: (int) $request->query('page', 1),
            search: (string) $request->query('query', ''),
        );

        return $this->jsonResponse([
            'data' => array_map(static fn ($item) => $item->toArray(), $page->items),
            'meta' => [
                'total' => $page->total,
                'current_page' => $page->current_page,
                'last_page' => $page->last_page,
                'per_page' => $page->per_page,
            ],
        ]);
    }
}

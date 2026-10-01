<?php

namespace HiEvents\Http\Actions\BoxOffice;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\BoxOfficeOrderFilterDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\GetBoxOfficeOrdersHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetBoxOfficeOrdersAction extends BaseAction
{
    public function __construct(
        private readonly GetBoxOfficeOrdersHandler $handler,
    ) {}

    public function __invoke(Request $request, int $eventId): JsonResponse
    {
        $this->isBoxOfficeActionAuthorized($eventId);

        $channel = $request->query('channel');

        $page = $this->handler->handle($eventId, new BoxOfficeOrderFilterDTO(
            query: (string) $request->query('query', ''),
            channel: in_array($channel, [BoxOfficeOrderFilterDTO::CHANNEL_ONLINE, BoxOfficeOrderFilterDTO::CHANNEL_BOX_OFFICE], true) ? $channel : null,
            agent_user_id: $request->boolean('mine') ? $this->getAuthenticatedUser()->getId() : null,
            not_checked_in: $request->boolean('not_checked_in'),
            cancelled: $request->boolean('cancelled'),
            page: max(1, (int) $request->query('page', 1)),
        ));

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

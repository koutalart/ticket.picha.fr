<?php

namespace HiEvents\Http\Actions\BoxOffice;

use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\BoxOffice\GetBoxOfficeOrderHandler;
use Illuminate\Http\JsonResponse;

class GetBoxOfficeOrderAction extends BaseAction
{
    public function __construct(
        private readonly GetBoxOfficeOrderHandler $handler,
    ) {}

    public function __invoke(int $eventId, string $orderPublicId): JsonResponse
    {
        $this->isBoxOfficeActionAuthorized($eventId);

        try {
            $detail = $this->handler->handle($eventId, $orderPublicId);
        } catch (ResourceNotFoundException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_NOT_FOUND);
        }

        return $this->jsonResponse([
            'order' => $detail->order->toArray(),
            'tickets' => array_map(static fn ($ticket) => $ticket->toArray(), $detail->tickets),
        ], wrapInData: true);
    }
}

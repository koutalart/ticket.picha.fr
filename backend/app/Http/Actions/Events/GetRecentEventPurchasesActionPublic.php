<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Events;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Event\GetRecentEventPurchasesHandler;
use Illuminate\Http\JsonResponse;

class GetRecentEventPurchasesActionPublic extends BaseAction
{
    public function __construct(
        private readonly GetRecentEventPurchasesHandler $handler,
    ) {}

    public function __invoke(int $eventId): JsonResponse
    {
        return $this->jsonResponse(
            data: $this->handler->handle($eventId)->toArray(),
            wrapInData: true,
        );
    }
}

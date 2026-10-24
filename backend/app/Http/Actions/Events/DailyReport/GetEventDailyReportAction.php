<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Events\DailyReport;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\EventDailyReportException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Event\EventDailyReportResource;
use HiEvents\Services\Application\Handlers\Event\DailyReport\GetEventDailyReportHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class GetEventDailyReportAction extends BaseAction
{
    public function __construct(
        private readonly GetEventDailyReportHandler $handler,
    ) {}

    public function __invoke(int $eventId): JsonResponse|Response
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        try {
            return $this->resourceResponse(EventDailyReportResource::class, $this->handler->handle($eventId));
        } catch (EventDailyReportException) {
            return $this->notFoundResponse();
        }
    }
}

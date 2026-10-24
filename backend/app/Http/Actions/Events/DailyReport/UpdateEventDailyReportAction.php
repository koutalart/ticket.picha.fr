<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Events\DailyReport;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\EventDailyReportException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Event\UpdateEventDailyReportRequest;
use HiEvents\Resources\Event\EventDailyReportResource;
use HiEvents\Services\Application\Handlers\Event\DailyReport\DTO\UpdateEventDailyReportDTO;
use HiEvents\Services\Application\Handlers\Event\DailyReport\UpdateEventDailyReportHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class UpdateEventDailyReportAction extends BaseAction
{
    public function __construct(
        private readonly UpdateEventDailyReportHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(UpdateEventDailyReportRequest $request, int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $validated = $request->validated();

        try {
            $view = $this->handler->handle(new UpdateEventDailyReportDTO(
                eventId: $eventId,
                enabled: array_key_exists('enabled', $validated) ? (bool) $validated['enabled'] : null,
                sendTime: $validated['send_time'] ?? null,
                timezone: $validated['timezone'] ?? null,
                copySupervisors: array_key_exists('copy_supervisors', $validated) ? (bool) $validated['copy_supervisors'] : null,
            ));
        } catch (EventDailyReportException $exception) {
            throw ValidationException::withMessages(['send_time' => $exception->getMessage()]);
        }

        return $this->resourceResponse(EventDailyReportResource::class, $view);
    }
}

<?php

namespace HiEvents\Http\Actions\EventFeedback;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Domain\EventFeedback\EventFeedbackCsvService;
use Illuminate\Http\Response;

class ExportEventFeedbackAction extends BaseAction
{
    public function __construct(
        private readonly EventFeedbackCsvService $csvService,
    ) {}

    public function __invoke(int $eventId): Response
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        return $this->csvResponse($this->csvService->build($eventId), sprintf('avis-evenement-%d.csv', $eventId));
    }
}

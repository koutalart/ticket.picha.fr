<?php

namespace HiEvents\Http\Actions\Attendees;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exports\AttendeeEntryControlListExport;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Attendee\GetAttendeeEntryControlListHandler;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportAttendeeEntryControlListAction extends BaseAction
{
    public function __construct(
        private readonly GetAttendeeEntryControlListHandler $handler,
        private readonly AttendeeEntryControlListExport $export,
    ) {}

    public function __invoke(int $eventId): BinaryFileResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        return Excel::download(
            $this->export->withData($this->handler->handle($eventId)),
            'liste-controle-entrees.xlsx'
        );
    }
}

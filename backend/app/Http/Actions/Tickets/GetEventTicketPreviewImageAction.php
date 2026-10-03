<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Tickets;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Ticket\GetAttendeeTicketImageHandler;
use Illuminate\Http\Response;

class GetEventTicketPreviewImageAction extends BaseAction
{
    public function __construct(
        private readonly GetAttendeeTicketImageHandler $handler,
    ) {}

    public function __invoke(int $eventId): Response
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        return response($this->handler->preview($eventId))
            ->header('Content-Type', 'image/png')
            ->header('Cache-Control', 'no-store');
    }
}

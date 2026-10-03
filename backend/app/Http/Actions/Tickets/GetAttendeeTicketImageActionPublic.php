<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Tickets;

use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Ticket\GetAttendeeTicketImageHandler;
use Illuminate\Http\Response;

class GetAttendeeTicketImageActionPublic extends BaseAction
{
    public function __construct(
        private readonly GetAttendeeTicketImageHandler $handler,
    ) {}

    public function __invoke(int $eventId, string $attendeeShortId): Response
    {
        try {
            $png = $this->handler->handle($eventId, $attendeeShortId);
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return response($png)
            ->header('Content-Type', 'image/png')
            ->header('Cache-Control', 'private, max-age=300');
    }
}

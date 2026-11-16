<?php

namespace HiEvents\Http\Actions\EventPresence;

use HiEvents\Exceptions\EventPresenceRejectedException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\EventPresence\SubmitEventPresenceRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\EventPresence\DTO\SubmitEventPresenceDTO;
use HiEvents\Services\Application\Handlers\EventPresence\SubmitEventPresenceHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class SubmitEventPresenceActionPublic extends BaseAction
{
    public function __construct(
        private readonly SubmitEventPresenceHandler $handler,
    ) {}

    public function __invoke(SubmitEventPresenceRequest $request, int $eventId): JsonResponse
    {
        try {
            $presence = $this->handler->handle(new SubmitEventPresenceDTO(
                event_id: $eventId,
                first_name: (string) $request->validated('prenom'),
                phone: (string) $request->validated('telephone'),
                last_name: $request->validated('nom'),
                staff: $request->validated('staff'),
                ip: $this->getClientIp($request),
            ));
        } catch (EventPresenceRejectedException $exception) {
            throw ValidationException::withMessages(['telephone' => $exception->getMessage()]);
        }

        return $this->jsonResponse(['ok' => true, 'telephone' => $presence->getPhone()], ResponseCodes::HTTP_CREATED);
    }
}

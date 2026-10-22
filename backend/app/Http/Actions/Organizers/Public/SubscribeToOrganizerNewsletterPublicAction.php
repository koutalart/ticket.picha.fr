<?php

namespace HiEvents\Http\Actions\Organizers\Public;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Organizer\DTO\SubscribeToOrganizerNewsletterDTO;
use HiEvents\Services\Application\Handlers\Organizer\SubscribeToOrganizerNewsletterHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SubscribeToOrganizerNewsletterPublicAction extends BaseAction
{
    public function __construct(
        private readonly SubscribeToOrganizerNewsletterHandler $handler,
    )
    {
    }

    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request, int $eventId): JsonResponse
    {
        $data = $this->validate($request, [
            'email' => 'required|email:rfc|max:255',
            'consent' => 'accepted',
            'consent_text' => 'nullable|string|max:1000',
        ]);

        $this->handler->handle(new SubscribeToOrganizerNewsletterDTO(
            event_id: $eventId,
            email: $data['email'],
            consent_text: $data['consent_text'] ?? null,
            ip: $request->ip(),
            user_agent: $request->userAgent(),
        ));

        return $this->jsonResponse([
            'message' => __('Thank you, your subscription has been recorded.'),
        ]);
    }
}

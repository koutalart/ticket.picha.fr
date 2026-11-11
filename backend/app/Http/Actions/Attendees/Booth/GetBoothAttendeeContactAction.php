<?php

namespace HiEvents\Http\Actions\Attendees\Booth;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Attendee\Booth\GetBoothAttendeeContactHandler;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GetBoothAttendeeContactAction extends BaseAction
{
    public const KEY_HEADER = 'X-Booth-Key';

    public function __construct(
        private readonly GetBoothAttendeeContactHandler $handler,
        private readonly Repository $config,
    ) {}

    public function __invoke(Request $request, int $eventId, string $attendeePublicId): JsonResponse|Response
    {
        $key = (string) $this->config->get('app.booth_api_key');

        if ($key === '' || ! hash_equals($key, (string) $request->header(self::KEY_HEADER))) {
            return $this->errorResponse(__('Unauthorized'), ResponseCodes::HTTP_UNAUTHORIZED);
        }

        $contact = $this->handler->handle($eventId, $attendeePublicId);

        if ($contact === null) {
            return $this->notFoundResponse();
        }

        return $this->jsonResponse($contact->toArray());
    }
}

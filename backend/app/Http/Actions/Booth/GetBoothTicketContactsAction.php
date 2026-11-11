<?php

namespace HiEvents\Http\Actions\Booth;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Booth\GetBoothTicketContactsHandler;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as LaravelResponse;
use Symfony\Component\HttpFoundation\Response;

class GetBoothTicketContactsAction extends BaseAction
{
    public function __construct(
        private readonly GetBoothTicketContactsHandler $handler,
        private readonly Repository $config,
    ) {}

    public function __invoke(int $eventId, string $attendeePublicId, Request $request): JsonResponse|LaravelResponse
    {
        $key = (string) $this->config->get('app.picha_booth_api_key');

        if ($key === '') {
            return $this->errorResponse(
                message: __('Booth verification is not configured'),
                statusCode: Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        if (! hash_equals($key, (string) $request->header(VerifyBoothTicketAction::KEY_HEADER))) {
            return $this->errorResponse(
                message: __('Unauthorized'),
                statusCode: Response::HTTP_UNAUTHORIZED,
            );
        }

        $contacts = $this->handler->handle($eventId, $attendeePublicId);

        if ($contacts === null) {
            return $this->notFoundResponse();
        }

        return $this->jsonResponse($contacts->toArray());
    }
}

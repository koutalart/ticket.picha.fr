<?php

namespace HiEvents\Http\Actions\Booth;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Booth\VerifyBoothTicketHandler;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyBoothTicketAction extends BaseAction
{
    public const KEY_HEADER = 'X-Booth-Key';

    public function __construct(
        private readonly VerifyBoothTicketHandler $handler,
        private readonly Repository $config,
    )
    {
    }

    public function __invoke(int $eventId, string $attendeePublicId, Request $request): JsonResponse
    {
        $key = (string) $this->config->get('app.picha_booth_api_key');

        if ($key === '') {
            return $this->errorResponse(
                message: __('Booth verification is not configured'),
                statusCode: Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        if (!hash_equals($key, (string) $request->header(self::KEY_HEADER))) {
            return $this->errorResponse(
                message: __('Unauthorized'),
                statusCode: Response::HTTP_UNAUTHORIZED,
            );
        }

        $result = $this->handler->handle($eventId, $attendeePublicId);

        return $this->jsonResponse(
            data: $result->toArray(),
            statusCode: $result->status === null ? Response::HTTP_NOT_FOUND : Response::HTTP_OK,
        );
    }
}

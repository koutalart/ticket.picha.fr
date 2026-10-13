<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organizers\Settings;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\MetaConversionsApiException;
use HiEvents\Exceptions\MetaConversionsApiNotConfiguredException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Organizer\Meta\TestMetaConversionsApiHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TestMetaConversionsApiAction extends BaseAction
{
    public function __construct(
        private readonly TestMetaConversionsApiHandler $handler,
    ) {}

    public function __invoke(Request $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $result = $this->handler->handle($organizerId, $request->ip(), $request->userAgent());
        } catch (MetaConversionsApiNotConfiguredException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_UNPROCESSABLE_ENTITY);
        } catch (MetaConversionsApiException $exception) {
            return $this->errorResponse(
                __('Meta rejected the connection: :message', ['message' => $exception->getMessage()]),
                ResponseCodes::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->jsonResponse($result->toArray(), wrapInData: true);
    }
}

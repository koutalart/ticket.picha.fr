<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Social;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\MetaAppNotConfiguredException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Social\GetMetaLoginUrlHandler;
use Illuminate\Http\JsonResponse;

class GetMetaLoginUrlAction extends BaseAction
{
    public function __construct(
        private readonly GetMetaLoginUrlHandler $handler,
    ) {}

    public function __invoke(int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $url = $this->handler->handle(
                $this->getAuthenticatedAccountId(),
                $organizerId,
                $this->getAuthenticatedUser()->getId(),
            );
        } catch (MetaAppNotConfiguredException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->jsonResponse(['url' => $url], wrapInData: true);
    }
}

<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Social;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\MetaOAuthException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Social\MetaPageResource;
use HiEvents\Services\Application\Handlers\Social\GetPendingMetaPagesHandler;
use Illuminate\Http\JsonResponse;

class GetPendingMetaPagesAction extends BaseAction
{
    public function __construct(
        private readonly GetPendingMetaPagesHandler $handler,
    ) {}

    public function __invoke(int $organizerId, string $selectionKey): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $pages = $this->handler->handle($this->getAuthenticatedAccountId(), $organizerId, $selectionKey);
        } catch (MetaOAuthException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_GONE);
        }

        return $this->resourceResponse(MetaPageResource::class, $pages);
    }
}

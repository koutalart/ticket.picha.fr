<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Social;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Social\SocialConnectionResource;
use HiEvents\Services\Application\Handlers\Social\GetSocialConnectionHandler;
use Illuminate\Http\JsonResponse;

class GetSocialConnectionAction extends BaseAction
{
    public function __construct(
        private readonly GetSocialConnectionHandler $handler,
    ) {}

    public function __invoke(int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        return $this->resourceResponse(
            SocialConnectionResource::class,
            $this->handler->handle($this->getAuthenticatedAccountId(), $organizerId),
        );
    }
}

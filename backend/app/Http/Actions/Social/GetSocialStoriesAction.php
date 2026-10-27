<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Social;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Social\SocialFeedItemResource;
use HiEvents\Services\Application\Handlers\Social\GetSocialStoriesHandler;
use Illuminate\Http\JsonResponse;

class GetSocialStoriesAction extends BaseAction
{
    public function __construct(
        private readonly GetSocialStoriesHandler $handler,
    ) {}

    public function __invoke(int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        return $this->resourceResponse(SocialFeedItemResource::class, $this->handler->handle($organizerId));
    }
}

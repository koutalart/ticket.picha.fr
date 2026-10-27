<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Social;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Social\SocialFeedEntryResource;
use HiEvents\Services\Application\Handlers\Social\GetSocialFeedHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetSocialFeedAction extends BaseAction
{
    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 200;

    public function __construct(
        private readonly GetSocialFeedHandler $handler,
    ) {}

    public function __invoke(Request $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $limit = max(1, min((int) $request->query('limit', self::DEFAULT_LIMIT), self::MAX_LIMIT));
        $entries = $this->handler->handle($organizerId, $limit);

        return $this->resourceResponse(
            SocialFeedEntryResource::class,
            $entries->take($limit)->values(),
            meta: ['has_more' => $entries->count() > $limit, 'limit' => $limit],
        );
    }
}

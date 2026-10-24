<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Social;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\SocialPostStatus;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Social\SocialPostResource;
use HiEvents\Services\Application\Handlers\Social\GetSocialPostsHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetSocialPostsAction extends BaseAction
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly GetSocialPostsHandler $handler,
    ) {}

    public function __invoke(Request $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $status = $request->query('status');
        $status = is_string($status) && in_array($status, SocialPostStatus::valuesArray(), true) ? $status : null;

        return $this->resourceResponse(
            SocialPostResource::class,
            $this->handler->handle($organizerId, $status, self::PER_PAGE),
        );
    }
}

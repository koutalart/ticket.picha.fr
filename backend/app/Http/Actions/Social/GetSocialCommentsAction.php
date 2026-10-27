<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Social;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\SocialCommentNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Social\SocialCommentResource;
use HiEvents\Services\Application\Handlers\Social\GetSocialCommentsHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class GetSocialCommentsAction extends BaseAction
{
    public function __construct(
        private readonly GetSocialCommentsHandler $handler,
    ) {}

    public function __invoke(int $organizerId, int $feedItemId): JsonResponse|Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            return $this->resourceResponse(SocialCommentResource::class, $this->handler->handle($organizerId, $feedItemId));
        } catch (SocialCommentNotFoundException) {
            return $this->notFoundResponse();
        }
    }
}

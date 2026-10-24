<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Social;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\SocialPostNotEditableException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Social\SocialPostResource;
use HiEvents\Services\Application\Handlers\Social\ScheduleSocialPostHandler;
use Illuminate\Http\JsonResponse;

class ScheduleSocialPostAction extends BaseAction
{
    public function __construct(
        private readonly ScheduleSocialPostHandler $handler,
    ) {}

    public function __invoke(int $organizerId, int $postId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $post = $this->handler->handle($organizerId, $postId, $this->getAuthenticatedUser()->getId());
        } catch (SocialPostNotEditableException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_CONFLICT);
        }

        return $this->resourceResponse(SocialPostResource::class, $post);
    }
}

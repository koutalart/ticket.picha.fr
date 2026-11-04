<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Social;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\SocialPostNotEditableException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Social\RequestSocialPostChangesRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Social\SocialPostResource;
use HiEvents\Services\Application\Handlers\Social\DTO\RequestSocialPostChangesDTO;
use HiEvents\Services\Application\Handlers\Social\RequestSocialPostChangesHandler;
use Illuminate\Http\JsonResponse;

class RequestSocialPostChangesAction extends BaseAction
{
    public function __construct(
        private readonly RequestSocialPostChangesHandler $handler,
    ) {}

    public function __invoke(RequestSocialPostChangesRequest $request, int $organizerId, int $postId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $post = $this->handler->handle(new RequestSocialPostChangesDTO(
                organizerId: $organizerId,
                postId: $postId,
                userId: $this->getAuthenticatedUser()->getId(),
                comment: (string) $request->validated('comment'),
            ));
        } catch (SocialPostNotEditableException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_CONFLICT);
        }

        return $this->resourceResponse(SocialPostResource::class, $post);
    }
}

<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Social;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\SocialCommentNotFoundException;
use HiEvents\Exceptions\SocialCommentReplyFailedException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Social\ReplyToSocialCommentRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Social\SocialCommentResource;
use HiEvents\Services\Application\Handlers\Social\ReplyToSocialCommentHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ReplyToSocialCommentAction extends BaseAction
{
    public function __construct(
        private readonly ReplyToSocialCommentHandler $handler,
    ) {}

    public function __invoke(ReplyToSocialCommentRequest $request, int $organizerId, int $commentId): JsonResponse|Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $reply = $this->handler->handle($organizerId, $commentId, $this->getAuthenticatedUser()->getId(), (string) $request->validated('message'));
        } catch (SocialCommentNotFoundException) {
            return $this->notFoundResponse();
        } catch (SocialCommentReplyFailedException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->resourceResponse(SocialCommentResource::class, $reply, ResponseCodes::HTTP_CREATED);
    }
}

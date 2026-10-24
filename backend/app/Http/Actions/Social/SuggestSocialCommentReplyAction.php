<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Social;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\SocialCommentNotFoundException;
use HiEvents\Exceptions\SocialCommentReplyFailedException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Social\SuggestSocialCommentReplyHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class SuggestSocialCommentReplyAction extends BaseAction
{
    public function __construct(
        private readonly SuggestSocialCommentReplyHandler $handler,
    ) {}

    public function __invoke(int $organizerId, int $commentId): JsonResponse|Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $message = $this->handler->handle($organizerId, $commentId);
        } catch (SocialCommentNotFoundException) {
            return $this->notFoundResponse();
        } catch (SocialCommentReplyFailedException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->jsonResponse(['message' => $message], wrapInData: true);
    }
}

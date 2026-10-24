<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Social;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\SocialPostNotEditableException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Social\DeleteSocialPostHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class DeleteSocialPostAction extends BaseAction
{
    public function __construct(
        private readonly DeleteSocialPostHandler $handler,
    ) {}

    public function __invoke(int $organizerId, int $postId): JsonResponse|Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $this->handler->handle($organizerId, $postId);
        } catch (SocialPostNotEditableException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_CONFLICT);
        }

        return $this->deletedResponse();
    }
}

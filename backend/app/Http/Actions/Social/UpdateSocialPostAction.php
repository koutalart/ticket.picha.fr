<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Social;

use Carbon\Carbon;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\InvalidSocialPostException;
use HiEvents\Exceptions\SocialPostNotEditableException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Social\UpdateSocialPostRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Social\SocialPostResource;
use HiEvents\Services\Application\Handlers\Social\DTO\UpdateSocialPostDTO;
use HiEvents\Services\Application\Handlers\Social\UpdateSocialPostHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class UpdateSocialPostAction extends BaseAction
{
    public function __construct(
        private readonly UpdateSocialPostHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(UpdateSocialPostRequest $request, int $organizerId, int $postId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $post = $this->handler->handle(new UpdateSocialPostDTO(
                accountId: $this->getAuthenticatedAccountId(),
                organizerId: $organizerId,
                postId: $postId,
                caption: $request->validated('caption'),
                media: $request->mediaItems(),
                link: $request->validated('link'),
                scheduledAt: $request->validated('scheduled_at') ? Carbon::parse($request->validated('scheduled_at'))->utc() : null,
            ));
        } catch (InvalidSocialPostException $exception) {
            throw ValidationException::withMessages(['caption' => $exception->getMessage()]);
        } catch (SocialPostNotEditableException $exception) {
            return $this->errorResponse($exception->getMessage(), ResponseCodes::HTTP_CONFLICT);
        }

        return $this->resourceResponse(SocialPostResource::class, $post);
    }
}

<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Social;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\SocialNetwork;
use HiEvents\DomainObjects\Enums\SocialPostFormat;
use HiEvents\DomainObjects\Enums\SocialPostSource;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\InvalidSocialPostException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Social\CreateSocialPostRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Social\SocialPostResource;
use HiEvents\Services\Application\Handlers\Social\CreateSocialPostsHandler;
use HiEvents\Services\Domain\Social\DTO\CreateSocialPostsDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateSocialPostsAction extends BaseAction
{
    public function __construct(
        private readonly CreateSocialPostsHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function __invoke(CreateSocialPostRequest $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $posts = $this->handler->handle(new CreateSocialPostsDTO(
                accountId: $this->getAuthenticatedAccountId(),
                organizerId: $organizerId,
                userId: $this->getAuthenticatedUser()->getId(),
                networks: array_map(fn (string $network) => SocialNetwork::fromName($network), $request->validated('networks')),
                format: SocialPostFormat::fromName((string) $request->validated('format')),
                caption: $request->validated('caption'),
                media: $request->mediaItems() ?? [],
                link: $request->validated('link'),
                scheduledAt: $request->validated('scheduled_at') ? Carbon::parse($request->validated('scheduled_at'))->utc() : null,
                source: SocialPostSource::PICHA,
                asDraft: (bool) $request->validated('as_draft', false),
            ));
        } catch (InvalidSocialPostException $exception) {
            throw ValidationException::withMessages(['format' => $exception->getMessage()]);
        }

        return $this->resourceResponse(SocialPostResource::class, $posts, ResponseCodes::HTTP_CREATED);
    }
}

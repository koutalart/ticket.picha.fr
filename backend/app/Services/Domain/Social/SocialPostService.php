<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Social;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\SocialMediaType;
use HiEvents\DomainObjects\Enums\SocialNetwork;
use HiEvents\DomainObjects\Enums\SocialPostFormat;
use HiEvents\DomainObjects\Enums\SocialPostSource;
use HiEvents\DomainObjects\Generated\SocialPostDomainObjectAbstract;
use HiEvents\DomainObjects\SocialPostDomainObject;
use HiEvents\DomainObjects\Status\SocialPostStatus;
use HiEvents\Exceptions\InvalidSocialPostException;
use HiEvents\Exceptions\SocialMediaFileNotFoundException;
use HiEvents\Exceptions\SocialPostNotEditableException;
use HiEvents\Repository\Interfaces\SocialPostRepositoryInterface;
use HiEvents\Services\Domain\Social\DTO\CreateSocialPostsDTO;
use HiEvents\Services\Domain\Social\DTO\SocialMediaItemDTO;
use HiEvents\Services\Domain\Social\DTO\SocialPostDataDTO;
use HiEvents\Services\Domain\Social\Media\SocialMediaFileService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Throwable;

class SocialPostService
{
    private const REPLACEABLE_STATUSES = [
        SocialPostStatus::PENDING_APPROVAL,
        SocialPostStatus::CHANGES_REQUESTED,
    ];

    private const EDITABLE_STATUSES = [
        SocialPostStatus::DRAFT,
        SocialPostStatus::PENDING_APPROVAL,
        SocialPostStatus::CHANGES_REQUESTED,
        SocialPostStatus::SCHEDULED,
        SocialPostStatus::FAILED,
        SocialPostStatus::CANCELLED,
    ];

    public function __construct(
        private readonly SocialPostRepositoryInterface $postRepository,
        private readonly MetaConnectionService $connectionService,
        private readonly SocialPostValidator $validator,
        private readonly DatabaseManager $databaseManager,
        private readonly SocialMediaFileService $mediaFileService,
    ) {}

    /**
     * Creates one post per network. Posts prepared by the assistant always wait for a person's approval.
     * A draft or a post to approve does not need a connected page: the page is only checked when the post is published.
     *
     * @return Collection<int, SocialPostDomainObject>
     *
     * @throws InvalidSocialPostException
     * @throws Throwable
     */
    public function create(CreateSocialPostsDTO $dto): Collection
    {
        if ($dto->networks === []) {
            throw new InvalidSocialPostException(__('Choose at least one network.'));
        }

        $caption = $this->cleanCaption($dto->caption);
        $media = $this->hydrateFiles($dto->organizerId, $dto->media);

        $status = match (true) {
            $dto->asDraft => SocialPostStatus::DRAFT,
            $dto->source === SocialPostSource::ASSISTANT => SocialPostStatus::PENDING_APPROVAL,
            default => SocialPostStatus::SCHEDULED,
        };

        $connection = $status === SocialPostStatus::SCHEDULED
            ? $this->connectionService->findForOrganizer($dto->accountId, $dto->organizerId)
            : null;

        foreach ($dto->networks as $network) {
            $this->validator->validate(new SocialPostDataDTO(
                network: $network,
                format: $dto->format,
                caption: $caption,
                media: $media,
                link: $dto->link,
                scheduledAt: $dto->scheduledAt,
            ));

            if ($status === SocialPostStatus::SCHEDULED) {
                $this->validator->validateConnection($network, $connection);
            }
        }

        $replaced = array_map(fn (int $postId) => $this->findReplaceable($dto->organizerId, $postId), array_unique($dto->replacesPostIds));

        $this->mediaFileService->keep($this->fileIds($media));

        return $this->databaseManager->transaction(function () use ($dto, $caption, $media, $status, $replaced) {
            $posts = $this->createPerNetwork($dto, $caption, $media, $status);

            foreach ($replaced as $old) {
                $this->postRepository->updateFromArray($old->getId(), [
                    SocialPostDomainObjectAbstract::STATUS => SocialPostStatus::CANCELLED->name,
                    SocialPostDomainObjectAbstract::NEXT_ATTEMPT_AT => null,
                    SocialPostDomainObjectAbstract::REPLACED_BY_POST_ID => $this->replacementFor($old, $posts)?->getId(),
                ]);
            }

            return $posts;
        });
    }

    /**
     * The new post of the same network when there is one, the first new post otherwise.
     *
     * @param  Collection<int, SocialPostDomainObject>  $posts
     */
    private function replacementFor(SocialPostDomainObject $old, Collection $posts): ?SocialPostDomainObject
    {
        return $posts->first(fn (SocialPostDomainObject $post) => $post->getNetwork() === $old->getNetwork()) ?? $posts->first();
    }

    /**
     * @param  SocialMediaItemDTO[]  $media
     * @return Collection<int, SocialPostDomainObject>
     */
    private function createPerNetwork(CreateSocialPostsDTO $dto, ?string $caption, array $media, SocialPostStatus $status): Collection
    {
        return collect(array_unique($dto->networks, SORT_REGULAR))
            ->map(fn (SocialNetwork $network) => $this->postRepository->create([
                SocialPostDomainObjectAbstract::ORGANIZER_ID => $dto->organizerId,
                SocialPostDomainObjectAbstract::ACCOUNT_ID => $dto->accountId,
                SocialPostDomainObjectAbstract::NETWORK => $network->name,
                SocialPostDomainObjectAbstract::FORMAT => $dto->format->name,
                SocialPostDomainObjectAbstract::CAPTION => $caption,
                SocialPostDomainObjectAbstract::MEDIA => $this->mediaToArray($media),
                SocialPostDomainObjectAbstract::LINK => $dto->link,
                SocialPostDomainObjectAbstract::STATUS => $status->name,
                SocialPostDomainObjectAbstract::SOURCE => $dto->source->name,
                SocialPostDomainObjectAbstract::SCHEDULED_AT => $dto->scheduledAt,
                SocialPostDomainObjectAbstract::NEXT_ATTEMPT_AT => $status === SocialPostStatus::SCHEDULED ? $this->firstAttemptAt($dto->scheduledAt) : null,
                SocialPostDomainObjectAbstract::CREATED_BY_USER_ID => $dto->userId,
                SocialPostDomainObjectAbstract::APPROVED_BY_USER_ID => $status === SocialPostStatus::SCHEDULED ? $dto->userId : null,
                SocialPostDomainObjectAbstract::APPROVED_AT => $status === SocialPostStatus::SCHEDULED ? Carbon::now() : null,
            ]))
            ->values();
    }

    /**
     * The organizer asks for changes to a post waiting for approval. The assistant reads the request and proposes a new version.
     *
     * @throws SocialPostNotEditableException
     */
    public function requestChanges(int $organizerId, int $postId, ?int $userId, string $comment): SocialPostDomainObject
    {
        $post = $this->findInOrganizer($organizerId, $postId);

        if ($post->getStatus() !== SocialPostStatus::PENDING_APPROVAL->name) {
            throw new SocialPostNotEditableException(__('Changes can only be requested on a post waiting for approval.'));
        }

        return $this->postRepository->updateFromArray($post->getId(), [
            SocialPostDomainObjectAbstract::STATUS => SocialPostStatus::CHANGES_REQUESTED->name,
            SocialPostDomainObjectAbstract::CHANGE_REQUEST_COMMENT => $this->cleanCaption($comment),
            SocialPostDomainObjectAbstract::CHANGE_REQUESTED_AT => Carbon::now(),
            SocialPostDomainObjectAbstract::CHANGE_REQUESTED_BY_USER_ID => $userId,
            SocialPostDomainObjectAbstract::NEXT_ATTEMPT_AT => null,
        ]);
    }

    /**
     * @return Collection<int, SocialPostDomainObject>
     */
    public function findChangeRequests(?int $organizerId): Collection
    {
        return $this->postRepository->findWhere(array_filter([
            SocialPostDomainObjectAbstract::STATUS => SocialPostStatus::CHANGES_REQUESTED->name,
            SocialPostDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ], fn ($value) => $value !== null))
            ->sortBy(fn (SocialPostDomainObject $post) => $post->getChangeRequestedAt())
            ->values();
    }

    /**
     * @param  SocialMediaItemDTO[]|null  $media
     *
     * @throws InvalidSocialPostException
     * @throws SocialPostNotEditableException
     */
    public function update(
        int $organizerId,
        int $postId,
        ?string $caption,
        ?array $media,
        ?string $link,
        ?Carbon $scheduledAt,
    ): SocialPostDomainObject {
        $post = $this->findEditable($organizerId, $postId);
        $media = $this->hydrateFiles($organizerId, $media ?? $this->mediaFromPost($post));
        $caption = $this->cleanCaption($caption);

        $this->validator->validate(new SocialPostDataDTO(
            network: SocialNetwork::fromName($post->getNetwork()),
            format: SocialPostFormat::fromName($post->getFormat()),
            caption: $caption,
            media: $media,
            link: $link,
            scheduledAt: $scheduledAt,
        ));

        $isScheduled = $post->getStatus() === SocialPostStatus::SCHEDULED->name;
        $this->mediaFileService->keep($this->fileIds($media));

        return $this->postRepository->updateFromArray($postId, [
            SocialPostDomainObjectAbstract::CAPTION => $caption,
            SocialPostDomainObjectAbstract::MEDIA => $this->mediaToArray($media),
            SocialPostDomainObjectAbstract::LINK => $link,
            SocialPostDomainObjectAbstract::SCHEDULED_AT => $scheduledAt,
            SocialPostDomainObjectAbstract::NEXT_ATTEMPT_AT => $isScheduled ? $this->firstAttemptAt($scheduledAt) : null,
            SocialPostDomainObjectAbstract::EXTERNAL_CONTAINER_ID => null,
        ]);
    }

    /**
     * Approves a draft, a pending or a failed post: it will be published at its scheduled time, or right away.
     * Without a connected page it stays scheduled and leaves as soon as a page is connected.
     *
     * @throws SocialPostNotEditableException
     */
    public function schedule(int $organizerId, int $postId, ?int $userId): SocialPostDomainObject
    {
        $post = $this->findEditable($organizerId, $postId);

        return $this->postRepository->updateFromArray($post->getId(), [
            SocialPostDomainObjectAbstract::STATUS => SocialPostStatus::SCHEDULED->name,
            SocialPostDomainObjectAbstract::NEXT_ATTEMPT_AT => $this->firstAttemptAt($post->getScheduledAt() ? Carbon::parse($post->getScheduledAt()) : null),
            SocialPostDomainObjectAbstract::ATTEMPTS => 0,
            SocialPostDomainObjectAbstract::LAST_ERROR => null,
            SocialPostDomainObjectAbstract::EXTERNAL_CONTAINER_ID => null,
            SocialPostDomainObjectAbstract::APPROVED_BY_USER_ID => $userId,
            SocialPostDomainObjectAbstract::APPROVED_AT => Carbon::now(),
        ]);
    }

    /**
     * @throws SocialPostNotEditableException
     */
    public function cancel(int $organizerId, int $postId): SocialPostDomainObject
    {
        $post = $this->findEditable($organizerId, $postId);

        return $this->postRepository->updateFromArray($post->getId(), [
            SocialPostDomainObjectAbstract::STATUS => SocialPostStatus::CANCELLED->name,
            SocialPostDomainObjectAbstract::NEXT_ATTEMPT_AT => null,
        ]);
    }

    /**
     * @throws SocialPostNotEditableException
     */
    public function delete(int $organizerId, int $postId): void
    {
        $post = $this->findEditable($organizerId, $postId);

        $this->postRepository->deleteById($post->getId());
        $this->mediaFileService->purgeAfterDays($this->fileIds($this->mediaFromPost($post)), 1);
    }

    /**
     * @return SocialMediaItemDTO[]
     */
    public function mediaFromPost(SocialPostDomainObject $post): array
    {
        $media = $post->getMedia();

        if (is_string($media)) {
            $media = json_decode($media, true);
        }

        return array_map(
            fn (array $item) => new SocialMediaItemDTO(
                url: (string) ($item['url'] ?? ''),
                type: SocialMediaType::fromName((string) $item['type']),
                fileId: isset($item['file_id']) ? (int) $item['file_id'] : null,
            ),
            is_array($media) ? $media : [],
        );
    }

    /**
     * @param  SocialMediaItemDTO[]  $media
     * @return int[]
     */
    public function fileIds(array $media): array
    {
        return array_values(array_filter(array_map(fn (SocialMediaItemDTO $item) => $item->fileId, $media)));
    }

    /**
     * Loads the type and metadata of the imported files, which must belong to the organizer.
     *
     * @param  SocialMediaItemDTO[]  $media
     * @return SocialMediaItemDTO[]
     *
     * @throws InvalidSocialPostException
     */
    private function hydrateFiles(int $organizerId, array $media): array
    {
        return array_map(function (SocialMediaItemDTO $item) use ($organizerId) {
            if (! $item->isFile()) {
                return $item;
            }

            try {
                $file = $this->mediaFileService->findReady($organizerId, (int) $item->fileId);
            } catch (SocialMediaFileNotFoundException) {
                throw new InvalidSocialPostException(__('An imported media is no longer available. Import it again.'));
            }

            return new SocialMediaItemDTO(
                url: '',
                type: SocialMediaType::fromName($file->getMediaType()),
                fileId: $file->getId(),
                mimeType: $file->getMimeType(),
                width: $file->getWidth(),
                height: $file->getHeight(),
                durationMs: $file->getDurationMs(),
            );
        }, array_values($media));
    }

    /**
     * @throws InvalidSocialPostException
     */
    private function findReplaceable(int $organizerId, int $postId): SocialPostDomainObject
    {
        try {
            $post = $this->findInOrganizer($organizerId, $postId);
        } catch (SocialPostNotEditableException $exception) {
            throw new InvalidSocialPostException($exception->getMessage());
        }

        if (! in_array(SocialPostStatus::fromName($post->getStatus()), self::REPLACEABLE_STATUSES, true)) {
            throw new InvalidSocialPostException(__('Only a post waiting for approval or with requested changes can be replaced.'));
        }

        return $post;
    }

    /**
     * @throws SocialPostNotEditableException
     */
    private function findInOrganizer(int $organizerId, int $postId): SocialPostDomainObject
    {
        /** @var SocialPostDomainObject|null $post */
        $post = $this->postRepository->findFirstWhere([
            SocialPostDomainObjectAbstract::ID => $postId,
            SocialPostDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        if ($post === null) {
            throw new SocialPostNotEditableException(__('Post not found.'));
        }

        return $post;
    }

    /**
     * @throws SocialPostNotEditableException
     */
    private function findEditable(int $organizerId, int $postId): SocialPostDomainObject
    {
        $post = $this->findInOrganizer($organizerId, $postId);

        if (! in_array(SocialPostStatus::fromName($post->getStatus()), self::EDITABLE_STATUSES, true)) {
            throw new SocialPostNotEditableException(__('This post is being published or is already published and can no longer be changed.'));
        }

        return $post;
    }

    private function firstAttemptAt(?Carbon $scheduledAt): Carbon
    {
        return $scheduledAt && $scheduledAt->isFuture() ? $scheduledAt : Carbon::now();
    }

    /**
     * @param  SocialMediaItemDTO[]  $media
     */
    private function mediaToArray(array $media): array
    {
        return array_map(fn (SocialMediaItemDTO $item) => $item->isFile()
            ? ['file_id' => $item->fileId, 'type' => $item->type->name]
            : ['url' => $item->url, 'type' => $item->type->name], array_values($media));
    }

    private function cleanCaption(?string $caption): ?string
    {
        if ($caption === null) {
            return null;
        }

        $caption = trim(strip_tags(str_replace("\r\n", "\n", $caption)));

        return $caption === '' ? null : $caption;
    }
}

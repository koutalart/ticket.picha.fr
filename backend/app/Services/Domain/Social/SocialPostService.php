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
use HiEvents\Exceptions\SocialPostNotEditableException;
use HiEvents\Repository\Interfaces\SocialPostRepositoryInterface;
use HiEvents\Services\Domain\Social\DTO\CreateSocialPostsDTO;
use HiEvents\Services\Domain\Social\DTO\SocialMediaItemDTO;
use HiEvents\Services\Domain\Social\DTO\SocialPostDataDTO;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Throwable;

class SocialPostService
{
    private const EDITABLE_STATUSES = [
        SocialPostStatus::DRAFT,
        SocialPostStatus::PENDING_APPROVAL,
        SocialPostStatus::SCHEDULED,
        SocialPostStatus::FAILED,
        SocialPostStatus::CANCELLED,
    ];

    public function __construct(
        private readonly SocialPostRepositoryInterface $postRepository,
        private readonly MetaConnectionService $connectionService,
        private readonly SocialPostValidator $validator,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * Creates one post per network. Posts prepared by the assistant always wait for a person's approval.
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

        $connection = $this->connectionService->findForOrganizer($dto->accountId, $dto->organizerId);
        $caption = $this->cleanCaption($dto->caption);

        foreach ($dto->networks as $network) {
            $this->validator->validate(new SocialPostDataDTO(
                network: $network,
                format: $dto->format,
                caption: $caption,
                media: $dto->media,
                link: $dto->link,
                scheduledAt: $dto->scheduledAt,
            ), $connection);
        }

        $status = match (true) {
            $dto->asDraft => SocialPostStatus::DRAFT,
            $dto->source === SocialPostSource::ASSISTANT => SocialPostStatus::PENDING_APPROVAL,
            default => SocialPostStatus::SCHEDULED,
        };

        return $this->databaseManager->transaction(fn () => collect(array_unique($dto->networks, SORT_REGULAR))
            ->map(fn (SocialNetwork $network) => $this->postRepository->create([
                SocialPostDomainObjectAbstract::ORGANIZER_ID => $dto->organizerId,
                SocialPostDomainObjectAbstract::ACCOUNT_ID => $dto->accountId,
                SocialPostDomainObjectAbstract::NETWORK => $network->name,
                SocialPostDomainObjectAbstract::FORMAT => $dto->format->name,
                SocialPostDomainObjectAbstract::CAPTION => $caption,
                SocialPostDomainObjectAbstract::MEDIA => $this->mediaToArray($dto->media),
                SocialPostDomainObjectAbstract::LINK => $dto->link,
                SocialPostDomainObjectAbstract::STATUS => $status->name,
                SocialPostDomainObjectAbstract::SOURCE => $dto->source->name,
                SocialPostDomainObjectAbstract::SCHEDULED_AT => $dto->scheduledAt,
                SocialPostDomainObjectAbstract::NEXT_ATTEMPT_AT => $status === SocialPostStatus::SCHEDULED ? $this->firstAttemptAt($dto->scheduledAt) : null,
                SocialPostDomainObjectAbstract::CREATED_BY_USER_ID => $dto->userId,
                SocialPostDomainObjectAbstract::APPROVED_BY_USER_ID => $status === SocialPostStatus::SCHEDULED ? $dto->userId : null,
                SocialPostDomainObjectAbstract::APPROVED_AT => $status === SocialPostStatus::SCHEDULED ? Carbon::now() : null,
            ]))
            ->values());
    }

    /**
     * @param  SocialMediaItemDTO[]|null  $media
     *
     * @throws InvalidSocialPostException
     * @throws SocialPostNotEditableException
     */
    public function update(
        int $accountId,
        int $organizerId,
        int $postId,
        ?string $caption,
        ?array $media,
        ?string $link,
        ?Carbon $scheduledAt,
    ): SocialPostDomainObject {
        $post = $this->findEditable($organizerId, $postId);
        $media ??= $this->mediaFromPost($post);
        $caption = $this->cleanCaption($caption);

        $this->validator->validate(new SocialPostDataDTO(
            network: SocialNetwork::fromName($post->getNetwork()),
            format: SocialPostFormat::fromName($post->getFormat()),
            caption: $caption,
            media: $media,
            link: $link,
            scheduledAt: $scheduledAt,
        ), $this->connectionService->findForOrganizer($accountId, $organizerId));

        $isScheduled = $post->getStatus() === SocialPostStatus::SCHEDULED->name;

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
            fn (array $item) => new SocialMediaItemDTO(url: (string) $item['url'], type: SocialMediaType::fromName((string) $item['type'])),
            is_array($media) ? $media : [],
        );
    }

    /**
     * @throws SocialPostNotEditableException
     */
    private function findEditable(int $organizerId, int $postId): SocialPostDomainObject
    {
        /** @var SocialPostDomainObject|null $post */
        $post = $this->postRepository->findFirstWhere([
            SocialPostDomainObjectAbstract::ID => $postId,
            SocialPostDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        if ($post === null) {
            throw new SocialPostNotEditableException(__('Post not found.'));
        }

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
        return array_map(fn (SocialMediaItemDTO $item) => ['url' => $item->url, 'type' => $item->type->name], array_values($media));
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

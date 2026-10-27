<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Social;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\SocialNetwork;
use HiEvents\DomainObjects\Enums\SocialPostFormat;
use HiEvents\DomainObjects\Generated\OrganizerSocialConnectionDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SocialPostDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSocialConnectionDomainObject;
use HiEvents\DomainObjects\SocialPostDomainObject;
use HiEvents\DomainObjects\Status\SocialConnectionStatus;
use HiEvents\DomainObjects\Status\SocialPostStatus;
use HiEvents\Exceptions\MetaGraphApiException;
use HiEvents\Exceptions\SocialMediaFileNotFoundException;
use HiEvents\Exceptions\SocialPublishingLimitReachedException;
use HiEvents\Repository\Interfaces\OrganizerSocialConnectionRepositoryInterface;
use HiEvents\Repository\Interfaces\SocialPostRepositoryInterface;
use HiEvents\Services\Domain\Social\DTO\SocialMediaItemDTO;
use HiEvents\Services\Domain\Social\DTO\SocialPublishResultDTO;
use HiEvents\Services\Domain\Social\Media\SocialMediaFileService;
use HiEvents\Services\Domain\Social\Media\SocialMediaUrlGenerator;
use HiEvents\Services\Infrastructure\Meta\MetaGraphClient;
use Psr\Log\LoggerInterface;

class SocialPublishingService
{
    public const MAX_ATTEMPTS = 5;

    public const CONTAINER_CHECK_DELAY_SECONDS = 30;

    public const PUBLISHING_LIMIT_DELAY_MINUTES = 60;

    private const RETRY_DELAYS_MINUTES = [2, 5, 15, 30];

    public function __construct(
        private readonly MetaGraphClient $client,
        private readonly SocialPostRepositoryInterface $postRepository,
        private readonly OrganizerSocialConnectionRepositoryInterface $connectionRepository,
        private readonly MetaConnectionService $connectionService,
        private readonly SocialPostService $postService,
        private readonly LoggerInterface $logger,
        private readonly SocialMediaFileService $mediaFileService,
        private readonly SocialMediaUrlGenerator $mediaUrlGenerator,
    ) {}

    public function publish(int $postId): SocialPublishResultDTO
    {
        /** @var SocialPostDomainObject|null $post */
        $post = $this->postRepository->findFirstWhere([SocialPostDomainObjectAbstract::ID => $postId]);

        if ($post === null || $post->getStatus() !== SocialPostStatus::PUBLISHING->name) {
            return new SocialPublishResultDTO(SocialPostStatus::CANCELLED);
        }

        /** @var OrganizerSocialConnectionDomainObject|null $connection */
        $connection = $this->connectionRepository->findFirstWhere([
            OrganizerSocialConnectionDomainObjectAbstract::ORGANIZER_ID => $post->getOrganizerId(),
        ]);

        if ($connection === null || $connection->getStatus() !== SocialConnectionStatus::ACTIVE->name) {
            return $this->fail($post, __('The Facebook connection must be renewed in the organizer settings.'));
        }

        try {
            return SocialNetwork::fromName($post->getNetwork()) === SocialNetwork::INSTAGRAM
                ? $this->publishToInstagram($post, $connection)
                : $this->publishToFacebook($post, $connection);
        } catch (SocialMediaFileNotFoundException) {
            return $this->fail($post, __('An imported media is no longer available. Import it again.'));
        } catch (SocialPublishingLimitReachedException $exception) {
            return $this->reschedule($post, Carbon::now()->addMinutes(self::PUBLISHING_LIMIT_DELAY_MINUTES), $exception->getMessage(), countAttempt: false);
        } catch (MetaGraphApiException $exception) {
            return $this->handleGraphError($post, $connection, $exception);
        }
    }

    /**
     * @throws MetaGraphApiException
     * @throws SocialPublishingLimitReachedException
     */
    private function publishToInstagram(SocialPostDomainObject $post, OrganizerSocialConnectionDomainObject $connection): SocialPublishResultDTO
    {
        $token = $connection->getPageAccessToken();
        $instagramId = (string) $connection->getInstagramAccountId();
        $containerId = $post->getExternalContainerId();

        if ($containerId === null) {
            $this->assertInstagramQuota($instagramId, $token);
            $containerId = $this->createInstagramContainer($post, $instagramId, $token);

            $this->postRepository->updateFromArray($post->getId(), [
                SocialPostDomainObjectAbstract::EXTERNAL_CONTAINER_ID => $containerId,
            ]);
        }

        $status = $this->client->getInstagramContainerStatus($containerId, $token);

        if ($status === 'IN_PROGRESS' || $status === '') {
            return $this->reschedule($post, Carbon::now()->addSeconds(self::CONTAINER_CHECK_DELAY_SECONDS), null, countAttempt: false);
        }

        if ($status !== 'FINISHED') {
            $this->postRepository->updateFromArray($post->getId(), [SocialPostDomainObjectAbstract::EXTERNAL_CONTAINER_ID => null]);

            return $this->fail($post, __('Instagram could not process the media (:status). Check the format and size of the file.', ['status' => $status]));
        }

        $mediaId = $this->client->publishInstagramContainer($instagramId, $containerId, $token);

        return $this->markPublished($post, $mediaId, $this->safePermalink($mediaId, $token, 'permalink'));
    }

    /**
     * @throws MetaGraphApiException
     */
    private function createInstagramContainer(SocialPostDomainObject $post, string $instagramId, string $token): string
    {
        $media = $this->resolvedMedia($post);
        $caption = $post->getCaption();
        $first = $media[0] ?? null;

        $params = match (SocialPostFormat::fromName($post->getFormat())) {
            SocialPostFormat::IMAGE => ['image_url' => $first?->url, 'caption' => $caption],
            SocialPostFormat::REEL => ['media_type' => 'REELS', 'video_url' => $first?->url, 'caption' => $caption, 'share_to_feed' => 'true'],
            SocialPostFormat::STORY => $first?->isVideo()
                ? ['media_type' => 'STORIES', 'video_url' => $first->url]
                : ['media_type' => 'STORIES', 'image_url' => $first?->url],
            SocialPostFormat::CAROUSEL => [
                'media_type' => 'CAROUSEL',
                'caption' => $caption,
                'children' => implode(',', array_map(
                    fn (SocialMediaItemDTO $item) => $this->client->createInstagramContainer($instagramId, $token, [
                        'image_url' => $item->url,
                        'is_carousel_item' => 'true',
                    ]),
                    $media,
                )),
            ],
            SocialPostFormat::TEXT => throw new MetaGraphApiException(__('Instagram does not accept text-only posts.')),
        };

        return $this->client->createInstagramContainer($instagramId, $token, array_filter($params, fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * @throws MetaGraphApiException
     * @throws SocialPublishingLimitReachedException
     */
    private function assertInstagramQuota(string $instagramId, string $token): void
    {
        $quota = $this->client->getInstagramPublishingQuota($instagramId, $token);

        if ($quota !== null && $quota['usage'] >= $quota['total']) {
            throw new SocialPublishingLimitReachedException(__('Instagram publishing limit reached (:total posts in 24 hours). The post will be retried later.', ['total' => $quota['total']]));
        }
    }

    /**
     * @throws MetaGraphApiException
     */
    private function publishToFacebook(SocialPostDomainObject $post, OrganizerSocialConnectionDomainObject $connection): SocialPublishResultDTO
    {
        $token = $connection->getPageAccessToken();
        $pageId = $connection->getFacebookPageId();
        $media = $this->resolvedMedia($post);
        $caption = $post->getCaption();
        $first = $media[0] ?? null;

        $postId = match (SocialPostFormat::fromName($post->getFormat())) {
            SocialPostFormat::TEXT => $this->client->publishPageFeedPost($pageId, $token, $caption, $post->getLink()),
            SocialPostFormat::IMAGE => (function () use ($pageId, $token, $first, $caption) {
                $photo = $this->client->publishPagePhoto($pageId, $token, (string) $first?->url, $caption);

                return $photo['post_id'] ?? $photo['id'];
            })(),
            SocialPostFormat::CAROUSEL => $this->client->publishPageFeedPost(
                $pageId,
                $token,
                $caption,
                null,
                array_map(fn (SocialMediaItemDTO $item) => $this->client->publishPagePhoto($pageId, $token, $item->url, null, false)['id'], $media),
            ),
            SocialPostFormat::REEL => $this->client->publishPageVideo($pageId, $token, 'video_reels', (string) $first?->url, $caption),
            SocialPostFormat::STORY => $first?->isVideo()
                ? $this->client->publishPageVideo($pageId, $token, 'video_stories', $first->url)
                : $this->client->publishPagePhotoStory($pageId, $token, $this->client->publishPagePhoto($pageId, $token, (string) $first?->url, null, false)['id']),
        };

        return $this->markPublished($post, $postId, $this->safePermalink($postId, $token, 'permalink_url'));
    }

    /**
     * Imported files are given to Meta through a signed URL valid long enough for a retried or delayed publication.
     *
     * @return SocialMediaItemDTO[]
     *
     * @throws SocialMediaFileNotFoundException
     */
    private function resolvedMedia(SocialPostDomainObject $post): array
    {
        return array_map(function (SocialMediaItemDTO $item) use ($post) {
            if (! $item->isFile()) {
                return $item;
            }

            $file = $this->mediaFileService->findReady($post->getOrganizerId(), (int) $item->fileId);

            return $item->withUrl($this->mediaUrlGenerator->publicUrl(
                $file,
                Carbon::now()->addHours(SocialMediaUrlGenerator::PUBLISHING_VALIDITY_HOURS),
            ));
        }, $this->postService->mediaFromPost($post));
    }

    private function handleGraphError(
        SocialPostDomainObject $post,
        OrganizerSocialConnectionDomainObject $connection,
        MetaGraphApiException $exception,
    ): SocialPublishResultDTO {
        $this->logger->warning('Social post publishing failed', [
            'post_id' => $post->getId(),
            'organizer_id' => $post->getOrganizerId(),
            'network' => $post->getNetwork(),
            'status' => $exception->getCode(),
            'graph_code' => $exception->getGraphErrorCode(),
            'message' => $exception->getMessage(),
        ]);

        if ($exception->isTokenInvalid()) {
            $this->connectionService->markNeedsReconnect($connection->getId(), $exception->getMessage());

            return $this->fail($post, __('Facebook refused access: the connection must be renewed in the organizer settings. (:message)', ['message' => $exception->getMessage()]));
        }

        if ($exception->isRetryable() && $post->getAttempts() + 1 < self::MAX_ATTEMPTS) {
            $delay = self::RETRY_DELAYS_MINUTES[min($post->getAttempts(), count(self::RETRY_DELAYS_MINUTES) - 1)];

            return $this->reschedule($post, Carbon::now()->addMinutes($delay), $exception->getMessage(), countAttempt: true);
        }

        return $this->fail($post, $exception->getMessage());
    }

    private function reschedule(SocialPostDomainObject $post, Carbon $nextAttemptAt, ?string $error, bool $countAttempt): SocialPublishResultDTO
    {
        $this->postRepository->updateFromArray($post->getId(), array_filter([
            SocialPostDomainObjectAbstract::STATUS => SocialPostStatus::SCHEDULED->name,
            SocialPostDomainObjectAbstract::NEXT_ATTEMPT_AT => $nextAttemptAt,
            SocialPostDomainObjectAbstract::ATTEMPTS => $countAttempt ? $post->getAttempts() + 1 : null,
            SocialPostDomainObjectAbstract::LAST_ERROR => $error,
        ], fn ($value) => $value !== null));

        return new SocialPublishResultDTO(SocialPostStatus::SCHEDULED, $error);
    }

    private function fail(SocialPostDomainObject $post, string $error): SocialPublishResultDTO
    {
        $this->postRepository->updateFromArray($post->getId(), [
            SocialPostDomainObjectAbstract::STATUS => SocialPostStatus::FAILED->name,
            SocialPostDomainObjectAbstract::NEXT_ATTEMPT_AT => null,
            SocialPostDomainObjectAbstract::ATTEMPTS => $post->getAttempts() + 1,
            SocialPostDomainObjectAbstract::LAST_ERROR => $error,
        ]);

        return new SocialPublishResultDTO(SocialPostStatus::FAILED, $error);
    }

    private function markPublished(SocialPostDomainObject $post, string $externalId, ?string $permalink): SocialPublishResultDTO
    {
        $this->postRepository->updateFromArray($post->getId(), [
            SocialPostDomainObjectAbstract::STATUS => SocialPostStatus::PUBLISHED->name,
            SocialPostDomainObjectAbstract::EXTERNAL_ID => $externalId,
            SocialPostDomainObjectAbstract::PERMALINK => $permalink,
            SocialPostDomainObjectAbstract::PUBLISHED_AT => Carbon::now(),
            SocialPostDomainObjectAbstract::NEXT_ATTEMPT_AT => null,
            SocialPostDomainObjectAbstract::LAST_ERROR => null,
        ]);

        $this->mediaFileService->purgeAfterDays(
            $this->postService->fileIds($this->postService->mediaFromPost($post)),
            SocialMediaFileService::PUBLISHED_RETENTION_DAYS,
        );

        return new SocialPublishResultDTO(SocialPostStatus::PUBLISHED);
    }

    private function safePermalink(string $objectId, string $token, string $field): ?string
    {
        try {
            return $this->client->getPermalink($objectId, $token, $field);
        } catch (MetaGraphApiException) {
            return null;
        }
    }
}

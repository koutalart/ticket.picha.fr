<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Social\Feed;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\SocialMediaType;
use HiEvents\DomainObjects\Enums\SocialNetwork;
use HiEvents\DomainObjects\Generated\OrganizerSocialConnectionDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SocialCommentDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SocialFeedItemDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SocialPostDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSocialConnectionDomainObject;
use HiEvents\DomainObjects\SocialFeedItemDomainObject;
use HiEvents\DomainObjects\SocialPostDomainObject;
use HiEvents\DomainObjects\Status\SocialConnectionStatus;
use HiEvents\Exceptions\InvalidSocialMediaFileException;
use HiEvents\Exceptions\MetaGraphApiException;
use HiEvents\Repository\Interfaces\OrganizerSocialConnectionRepositoryInterface;
use HiEvents\Repository\Interfaces\SocialCommentRepositoryInterface;
use HiEvents\Repository\Interfaces\SocialFeedItemRepositoryInterface;
use HiEvents\Repository\Interfaces\SocialPostRepositoryInterface;
use HiEvents\Services\Domain\Social\Media\SocialMediaFileService;
use HiEvents\Services\Domain\Social\MetaConnectionService;
use HiEvents\Services\Infrastructure\Meta\MetaGraphClient;
use Psr\Log\LoggerInterface;

/**
 * Copies the latest Instagram and Facebook posts of an organizer, their comments and the Instagram stories, so PICHA shows them.
 */
class SocialFeedSyncService
{
    public const FEED_LIMIT = 25;

    public const COMMENTS_FRESH_SECONDS = 30;

    public const STORY_RETENTION_DAYS = 90;

    private const STORY = 'STORY';

    public function __construct(
        private readonly MetaGraphClient $client,
        private readonly OrganizerSocialConnectionRepositoryInterface $connectionRepository,
        private readonly SocialFeedItemRepositoryInterface $feedItemRepository,
        private readonly SocialCommentRepositoryInterface $commentRepository,
        private readonly SocialPostRepositoryInterface $postRepository,
        private readonly MetaConnectionService $connectionService,
        private readonly SocialMediaFileService $mediaFileService,
        private readonly LoggerInterface $logger,
    ) {}

    public function syncOrganizer(int $organizerId): void
    {
        $connection = $this->activeConnection($organizerId);

        if ($connection === null) {
            return;
        }

        try {
            $items = [];

            if ($connection->getInstagramAccountId()) {
                foreach ($this->client->getInstagramMedia($connection->getInstagramAccountId(), $connection->getPageAccessToken(), self::FEED_LIMIT) as $media) {
                    $items[] = $this->storeInstagramMedia($connection, $media);
                }
            }

            foreach ($this->client->getPagePosts($connection->getFacebookPageId(), $connection->getPageAccessToken(), self::FEED_LIMIT) as $post) {
                $items[] = $this->storeFacebookPost($connection, $post);
            }

            foreach ($items as [$item, $commentsChanged]) {
                if ($commentsChanged) {
                    $this->syncCommentsWith($connection, $item);
                }
            }
        } catch (MetaGraphApiException $exception) {
            $this->handleError($connection, $exception);

            return;
        }

        $this->syncStories($connection);
    }

    /**
     * Instagram removes a story after 24 hours: each new one is copied while it is online.
     */
    private function syncStories(OrganizerSocialConnectionDomainObject $connection): void
    {
        if (! $connection->getInstagramAccountId()) {
            return;
        }

        try {
            $stories = $this->client->getInstagramStories($connection->getInstagramAccountId(), $connection->getPageAccessToken());
        } catch (MetaGraphApiException $exception) {
            $this->handleError($connection, $exception);

            return;
        }

        foreach ($stories as $story) {
            $this->storeStory($connection, $story);
        }
    }

    private function storeStory(OrganizerSocialConnectionDomainObject $connection, array $story): void
    {
        $externalId = (string) $story['id'];

        /** @var SocialFeedItemDomainObject|null $existing */
        $existing = $this->feedItemRepository->findFirstWhere([
            SocialFeedItemDomainObjectAbstract::ORGANIZER_ID => $connection->getOrganizerId(),
            SocialFeedItemDomainObjectAbstract::NETWORK => SocialNetwork::INSTAGRAM->name,
            SocialFeedItemDomainObjectAbstract::EXTERNAL_ID => $externalId,
        ]);

        if ($existing !== null || empty($story['media_url'])) {
            return;
        }

        $publishedAt = $this->date($story['timestamp'] ?? null) ?? Carbon::now();
        $type = ($story['media_type'] ?? 'IMAGE') === 'VIDEO' ? SocialMediaType::VIDEO : SocialMediaType::IMAGE;
        $localPath = tempnam(sys_get_temp_dir(), 'story');

        try {
            $this->client->downloadMedia((string) $story['media_url'], $localPath);
            $file = $this->mediaFileService->storeCopy(
                $connection->getAccountId(),
                $connection->getOrganizerId(),
                $localPath,
                'story-'.$externalId,
                $publishedAt->copy()->addDays(self::STORY_RETENTION_DAYS),
            );
        } catch (MetaGraphApiException|InvalidSocialMediaFileException $exception) {
            $this->logger->warning('Instagram story could not be copied', [
                'organizer_id' => $connection->getOrganizerId(),
                'story_id' => $externalId,
                'message' => $exception->getMessage(),
            ]);

            return;
        } finally {
            if (is_file($localPath)) {
                unlink($localPath);
            }
        }

        $this->feedItemRepository->upsertFromNetwork($connection->getOrganizerId(), SocialNetwork::INSTAGRAM->name, $externalId, [
            SocialFeedItemDomainObjectAbstract::ACCOUNT_ID => $connection->getAccountId(),
            SocialFeedItemDomainObjectAbstract::MEDIA_TYPE => $type->name,
            SocialFeedItemDomainObjectAbstract::MEDIA_PRODUCT_TYPE => self::STORY,
            SocialFeedItemDomainObjectAbstract::CAPTION => $story['caption'] ?? null,
            SocialFeedItemDomainObjectAbstract::CHILDREN => [['file_id' => $file->getId(), 'type' => $type->name]],
            SocialFeedItemDomainObjectAbstract::PERMALINK => $story['permalink'] ?? null,
            SocialFeedItemDomainObjectAbstract::PUBLISHED_AT => $publishedAt,
        ]);
    }

    /**
     * Refreshes the comments of a post when the last copy is older than a few seconds.
     */
    public function syncCommentsIfStale(SocialFeedItemDomainObject $item): void
    {
        $syncedAt = $item->getCommentsSyncedAt() ? Carbon::parse($item->getCommentsSyncedAt()) : null;

        if ($syncedAt !== null && $syncedAt->diffInSeconds(Carbon::now()) < self::COMMENTS_FRESH_SECONDS) {
            return;
        }

        $connection = $this->activeConnection($item->getOrganizerId());

        if ($connection === null) {
            return;
        }

        try {
            $this->syncCommentsWith($connection, $item);
        } catch (MetaGraphApiException $exception) {
            $this->handleError($connection, $exception);
        }
    }

    public function activeConnection(int $organizerId): ?OrganizerSocialConnectionDomainObject
    {
        /** @var OrganizerSocialConnectionDomainObject|null $connection */
        $connection = $this->connectionRepository->findFirstWhere([
            OrganizerSocialConnectionDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        return $connection !== null && $connection->getStatus() === SocialConnectionStatus::ACTIVE->name ? $connection : null;
    }

    /**
     * @throws MetaGraphApiException
     */
    private function syncCommentsWith(OrganizerSocialConnectionDomainObject $connection, SocialFeedItemDomainObject $item): void
    {
        $isInstagram = $item->getNetwork() === SocialNetwork::INSTAGRAM->name;
        $remote = $isInstagram
            ? $this->client->getInstagramComments($item->getExternalId(), $connection->getPageAccessToken())
            : $this->client->getPostComments($item->getExternalId(), $connection->getPageAccessToken());

        $seen = [];

        foreach ($remote as $comment) {
            $seen[] = $this->storeComment($connection, $item, $comment, null);

            $replies = $isInstagram ? ($comment['replies']['data'] ?? []) : ($comment['comments']['data'] ?? []);

            foreach ($replies as $reply) {
                $seen[] = $this->storeComment($connection, $item, $reply, (string) $comment['id']);
            }
        }

        foreach ($this->commentRepository->findForFeedItem($item->getId()) as $local) {
            if (! in_array($local->getExternalId(), $seen, true) && count($remote) < 50) {
                $this->commentRepository->deleteFromNetwork($local->getNetwork(), $local->getExternalId());
            }
        }

        $this->feedItemRepository->updateFromArray($item->getId(), [
            SocialFeedItemDomainObjectAbstract::COMMENTS_SYNCED_AT => Carbon::now(),
        ]);
    }

    private function storeComment(OrganizerSocialConnectionDomainObject $connection, SocialFeedItemDomainObject $item, array $comment, ?string $parentId): string
    {
        $isInstagram = $item->getNetwork() === SocialNetwork::INSTAGRAM->name;
        $authorId = isset($comment['from']['id']) ? (string) $comment['from']['id'] : null;
        $authorName = $isInstagram
            ? ($comment['from']['username'] ?? $comment['username'] ?? null)
            : ($comment['from']['name'] ?? null);
        $isFromPage = $isInstagram
            ? ($authorId !== null && $authorId === $connection->getInstagramAccountId()) || ($authorName !== null && $authorName === $connection->getInstagramUsername())
            : $authorId !== null && $authorId === $connection->getFacebookPageId();

        $this->commentRepository->upsertFromNetwork($item->getNetwork(), (string) $comment['id'], [
            SocialCommentDomainObjectAbstract::ORGANIZER_ID => $item->getOrganizerId(),
            SocialCommentDomainObjectAbstract::SOCIAL_FEED_ITEM_ID => $item->getId(),
            SocialCommentDomainObjectAbstract::PARENT_EXTERNAL_ID => $parentId,
            SocialCommentDomainObjectAbstract::AUTHOR_ID => $authorId,
            SocialCommentDomainObjectAbstract::AUTHOR_NAME => $authorName,
            SocialCommentDomainObjectAbstract::MESSAGE => $isInstagram ? ($comment['text'] ?? null) : ($comment['message'] ?? null),
            SocialCommentDomainObjectAbstract::LIKE_COUNT => (int) ($comment['like_count'] ?? 0),
            SocialCommentDomainObjectAbstract::IS_FROM_PAGE => $isFromPage,
            SocialCommentDomainObjectAbstract::COMMENTED_AT => $this->date($comment['timestamp'] ?? $comment['created_time'] ?? null),
        ]);

        return (string) $comment['id'];
    }

    /**
     * @return array{0: SocialFeedItemDomainObject, 1: bool}
     */
    private function storeInstagramMedia(OrganizerSocialConnectionDomainObject $connection, array $media): array
    {
        $type = (string) ($media['media_type'] ?? 'IMAGE');
        $items = $type === 'CAROUSEL_ALBUM'
            ? array_map(fn (array $child) => $this->mediaEntry($child['media_type'] ?? 'IMAGE', $child['media_url'] ?? null, $child['thumbnail_url'] ?? null), $media['children']['data'] ?? [])
            : [$this->mediaEntry($type, $media['media_url'] ?? null, $media['thumbnail_url'] ?? null)];

        return $this->store($connection, SocialNetwork::INSTAGRAM, (string) $media['id'], [
            SocialFeedItemDomainObjectAbstract::MEDIA_TYPE => $type,
            SocialFeedItemDomainObjectAbstract::MEDIA_PRODUCT_TYPE => $media['media_product_type'] ?? null,
            SocialFeedItemDomainObjectAbstract::CAPTION => $media['caption'] ?? null,
            SocialFeedItemDomainObjectAbstract::MEDIA_URL => $media['media_url'] ?? null,
            SocialFeedItemDomainObjectAbstract::THUMBNAIL_URL => $media['thumbnail_url'] ?? null,
            SocialFeedItemDomainObjectAbstract::CHILDREN => array_values(array_filter($items)),
            SocialFeedItemDomainObjectAbstract::PERMALINK => $media['permalink'] ?? null,
            SocialFeedItemDomainObjectAbstract::PUBLISHED_AT => $this->date($media['timestamp'] ?? null),
            SocialFeedItemDomainObjectAbstract::LIKE_COUNT => (int) ($media['like_count'] ?? 0),
            SocialFeedItemDomainObjectAbstract::COMMENTS_COUNT => (int) ($media['comments_count'] ?? 0),
        ]);
    }

    /**
     * @return array{0: SocialFeedItemDomainObject, 1: bool}
     */
    private function storeFacebookPost(OrganizerSocialConnectionDomainObject $connection, array $post): array
    {
        $attachment = $post['attachments']['data'][0] ?? null;
        $subattachments = $attachment['subattachments']['data'] ?? [];
        $sources = $subattachments !== [] ? $subattachments : ($attachment ? [$attachment] : []);
        $items = array_values(array_filter(array_map(fn (array $entry) => $this->facebookMediaEntry($entry), $sources)));

        if ($items === [] && ! empty($post['full_picture'])) {
            $items = [$this->mediaEntry('IMAGE', $post['full_picture'], null)];
        }

        $mediaType = match (true) {
            count($items) > 1 => 'CAROUSEL_ALBUM',
            ($items[0]['type'] ?? null) === SocialMediaType::VIDEO->name => 'VIDEO',
            $items !== [] => 'IMAGE',
            default => 'TEXT',
        };

        return $this->store($connection, SocialNetwork::FACEBOOK, (string) $post['id'], [
            SocialFeedItemDomainObjectAbstract::MEDIA_TYPE => $mediaType,
            SocialFeedItemDomainObjectAbstract::MEDIA_PRODUCT_TYPE => $attachment['type'] ?? null,
            SocialFeedItemDomainObjectAbstract::CAPTION => $post['message'] ?? null,
            SocialFeedItemDomainObjectAbstract::MEDIA_URL => $items[0]['url'] ?? null,
            SocialFeedItemDomainObjectAbstract::THUMBNAIL_URL => $items[0]['thumbnail_url'] ?? null,
            SocialFeedItemDomainObjectAbstract::CHILDREN => $items,
            SocialFeedItemDomainObjectAbstract::PERMALINK => $post['permalink_url'] ?? null,
            SocialFeedItemDomainObjectAbstract::PUBLISHED_AT => $this->date($post['created_time'] ?? null),
            SocialFeedItemDomainObjectAbstract::LIKE_COUNT => (int) ($post['reactions']['summary']['total_count'] ?? 0),
            SocialFeedItemDomainObjectAbstract::COMMENTS_COUNT => (int) ($post['comments']['summary']['total_count'] ?? 0),
        ]);
    }

    /**
     * @return array{0: SocialFeedItemDomainObject, 1: bool}
     */
    private function store(OrganizerSocialConnectionDomainObject $connection, SocialNetwork $network, string $externalId, array $attributes): array
    {
        /** @var SocialFeedItemDomainObject|null $existing */
        $existing = $this->feedItemRepository->findFirstWhere([
            SocialFeedItemDomainObjectAbstract::ORGANIZER_ID => $connection->getOrganizerId(),
            SocialFeedItemDomainObjectAbstract::NETWORK => $network->name,
            SocialFeedItemDomainObjectAbstract::EXTERNAL_ID => $externalId,
        ]);

        $item = $this->feedItemRepository->upsertFromNetwork($connection->getOrganizerId(), $network->name, $externalId, array_merge($attributes, [
            SocialFeedItemDomainObjectAbstract::ACCOUNT_ID => $connection->getAccountId(),
            SocialFeedItemDomainObjectAbstract::SOCIAL_POST_ID => $this->pichaPostId($connection->getOrganizerId(), $network, $externalId),
        ]));

        $commentsChanged = $attributes[SocialFeedItemDomainObjectAbstract::COMMENTS_COUNT] > 0
            && ($existing === null || $existing->getCommentsSyncedAt() === null || $existing->getCommentsCount() !== $attributes[SocialFeedItemDomainObjectAbstract::COMMENTS_COUNT]);

        return [$item, $commentsChanged];
    }

    private function pichaPostId(int $organizerId, SocialNetwork $network, string $externalId): ?int
    {
        /** @var SocialPostDomainObject|null $post */
        $post = $this->postRepository->findFirstWhere([
            SocialPostDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            SocialPostDomainObjectAbstract::NETWORK => $network->name,
            SocialPostDomainObjectAbstract::EXTERNAL_ID => $externalId,
        ]);

        return $post?->getId();
    }

    private function facebookMediaEntry(array $entry): ?array
    {
        $isVideo = str_contains((string) ($entry['media_type'] ?? $entry['type'] ?? ''), 'video');
        $image = $entry['media']['image']['src'] ?? null;

        return $isVideo && ! empty($entry['media']['source'])
            ? $this->mediaEntry('VIDEO', $entry['media']['source'], $image)
            : $this->mediaEntry('IMAGE', $image, null);
    }

    private function mediaEntry(string $type, ?string $url, ?string $thumbnailUrl): ?array
    {
        if ($url === null || $url === '') {
            return null;
        }

        return [
            'url' => $url,
            'type' => $type === 'VIDEO' ? SocialMediaType::VIDEO->name : SocialMediaType::IMAGE->name,
            'thumbnail_url' => $thumbnailUrl,
        ];
    }

    private function date(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value)->utc() : null;
    }

    private function handleError(OrganizerSocialConnectionDomainObject $connection, MetaGraphApiException $exception): void
    {
        $this->logger->warning('Social feed synchronisation failed', [
            'organizer_id' => $connection->getOrganizerId(),
            'graph_code' => $exception->getGraphErrorCode(),
            'message' => $exception->getMessage(),
        ]);

        if ($exception->isTokenInvalid()) {
            $this->connectionService->markNeedsReconnect($connection->getId(), $exception->getMessage());
        }
    }
}

<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Social\Feed;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\SocialNetwork;
use HiEvents\DomainObjects\Generated\OrganizerSocialConnectionDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SocialCommentDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SocialFeedItemDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSocialConnectionDomainObject;
use HiEvents\DomainObjects\SocialFeedItemDomainObject;
use HiEvents\Jobs\Social\SyncSocialFeedJob;
use HiEvents\Repository\Interfaces\OrganizerSocialConnectionRepositoryInterface;
use HiEvents\Repository\Interfaces\SocialCommentRepositoryInterface;
use HiEvents\Repository\Interfaces\SocialFeedItemRepositoryInterface;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Webhooks of the Meta app: Instagram "comments" and Page "feed". Comments are stored at once; any other change of the
 * feed triggers a synchronisation of the organizer.
 */
class MetaWebhookService
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly OrganizerSocialConnectionRepositoryInterface $connectionRepository,
        private readonly SocialFeedItemRepositoryInterface $feedItemRepository,
        private readonly SocialCommentRepositoryInterface $commentRepository,
    ) {}

    public function verifyChallenge(?string $mode, ?string $verifyToken, ?string $challenge): ?string
    {
        $expected = (string) $this->config->get('services.meta.webhook_verify_token');

        if ($mode !== 'subscribe' || $expected === '' || $challenge === null || ! hash_equals($expected, (string) $verifyToken)) {
            return null;
        }

        return $challenge;
    }

    public function hasValidSignature(string $rawBody, ?string $signatureHeader): bool
    {
        $secret = (string) $this->config->get('services.meta.app_secret');

        if ($secret === '' || $signatureHeader === null || ! str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $rawBody, $secret), $signatureHeader);
    }

    public function handle(array $payload): void
    {
        $object = $payload['object'] ?? null;

        foreach ($payload['entry'] ?? [] as $entry) {
            $connection = match ($object) {
                'instagram' => $this->connectionBy(OrganizerSocialConnectionDomainObjectAbstract::INSTAGRAM_ACCOUNT_ID, (string) ($entry['id'] ?? '')),
                'page' => $this->connectionBy(OrganizerSocialConnectionDomainObjectAbstract::FACEBOOK_PAGE_ID, (string) ($entry['id'] ?? '')),
                default => null,
            };

            if ($connection === null) {
                continue;
            }

            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                if ($object === 'instagram' && ($change['field'] ?? null) === 'comments') {
                    $this->storeInstagramComment($connection, $value);
                } elseif ($object === 'page' && ($change['field'] ?? null) === 'feed' && ($value['item'] ?? null) === 'comment') {
                    $this->storeFacebookComment($connection, $value);
                } else {
                    SyncSocialFeedJob::dispatch($connection->getOrganizerId());
                }
            }
        }
    }

    private function storeInstagramComment(OrganizerSocialConnectionDomainObject $connection, array $value): void
    {
        $item = $this->feedItem($connection, SocialNetwork::INSTAGRAM, (string) ($value['media']['id'] ?? ''));

        if ($item === null || empty($value['id'])) {
            SyncSocialFeedJob::dispatch($connection->getOrganizerId());

            return;
        }

        $authorId = isset($value['from']['id']) ? (string) $value['from']['id'] : null;
        $username = $value['from']['username'] ?? null;

        $this->commentRepository->upsertFromNetwork(SocialNetwork::INSTAGRAM->name, (string) $value['id'], [
            SocialCommentDomainObjectAbstract::ORGANIZER_ID => $connection->getOrganizerId(),
            SocialCommentDomainObjectAbstract::SOCIAL_FEED_ITEM_ID => $item->getId(),
            SocialCommentDomainObjectAbstract::PARENT_EXTERNAL_ID => isset($value['parent_id']) ? (string) $value['parent_id'] : null,
            SocialCommentDomainObjectAbstract::AUTHOR_ID => $authorId,
            SocialCommentDomainObjectAbstract::AUTHOR_NAME => $username,
            SocialCommentDomainObjectAbstract::MESSAGE => $value['text'] ?? null,
            SocialCommentDomainObjectAbstract::IS_FROM_PAGE => $authorId === $connection->getInstagramAccountId() || ($username !== null && $username === $connection->getInstagramUsername()),
            SocialCommentDomainObjectAbstract::COMMENTED_AT => Carbon::now(),
        ]);
    }

    private function storeFacebookComment(OrganizerSocialConnectionDomainObject $connection, array $value): void
    {
        $commentId = (string) ($value['comment_id'] ?? '');

        if ($commentId === '') {
            return;
        }

        if (($value['verb'] ?? null) === 'remove') {
            $this->commentRepository->deleteFromNetwork(SocialNetwork::FACEBOOK->name, $commentId);

            return;
        }

        $item = $this->feedItem($connection, SocialNetwork::FACEBOOK, (string) ($value['post_id'] ?? ''));

        if ($item === null) {
            SyncSocialFeedJob::dispatch($connection->getOrganizerId());

            return;
        }

        $parentId = isset($value['parent_id']) && $value['parent_id'] !== ($value['post_id'] ?? null) ? (string) $value['parent_id'] : null;
        $authorId = isset($value['from']['id']) ? (string) $value['from']['id'] : null;

        $this->commentRepository->upsertFromNetwork(SocialNetwork::FACEBOOK->name, $commentId, [
            SocialCommentDomainObjectAbstract::ORGANIZER_ID => $connection->getOrganizerId(),
            SocialCommentDomainObjectAbstract::SOCIAL_FEED_ITEM_ID => $item->getId(),
            SocialCommentDomainObjectAbstract::PARENT_EXTERNAL_ID => $parentId,
            SocialCommentDomainObjectAbstract::AUTHOR_ID => $authorId,
            SocialCommentDomainObjectAbstract::AUTHOR_NAME => $value['from']['name'] ?? null,
            SocialCommentDomainObjectAbstract::MESSAGE => $value['message'] ?? null,
            SocialCommentDomainObjectAbstract::IS_FROM_PAGE => $authorId === $connection->getFacebookPageId(),
            SocialCommentDomainObjectAbstract::COMMENTED_AT => isset($value['created_time']) ? Carbon::createFromTimestamp((int) $value['created_time']) : Carbon::now(),
        ]);
    }

    private function feedItem(OrganizerSocialConnectionDomainObject $connection, SocialNetwork $network, string $externalId): ?SocialFeedItemDomainObject
    {
        if ($externalId === '') {
            return null;
        }

        /** @var SocialFeedItemDomainObject|null $item */
        $item = $this->feedItemRepository->findFirstWhere([
            SocialFeedItemDomainObjectAbstract::ORGANIZER_ID => $connection->getOrganizerId(),
            SocialFeedItemDomainObjectAbstract::NETWORK => $network->name,
            SocialFeedItemDomainObjectAbstract::EXTERNAL_ID => $externalId,
        ]);

        return $item;
    }

    private function connectionBy(string $column, string $value): ?OrganizerSocialConnectionDomainObject
    {
        if ($value === '') {
            return null;
        }

        /** @var OrganizerSocialConnectionDomainObject|null $connection */
        $connection = $this->connectionRepository->findFirstWhere([$column => $value]);

        return $connection;
    }
}

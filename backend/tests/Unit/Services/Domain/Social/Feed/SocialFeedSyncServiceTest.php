<?php

namespace Tests\Unit\Services\Domain\Social\Feed;

use HiEvents\DomainObjects\Generated\SocialCommentDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SocialFeedItemDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSocialConnectionDomainObject;
use HiEvents\DomainObjects\SocialCommentDomainObject;
use HiEvents\DomainObjects\SocialFeedItemDomainObject;
use HiEvents\DomainObjects\SocialMediaFileDomainObject;
use HiEvents\DomainObjects\SocialPostDomainObject;
use HiEvents\DomainObjects\Status\SocialConnectionStatus;
use HiEvents\Exceptions\MetaGraphApiException;
use HiEvents\Repository\Interfaces\OrganizerSocialConnectionRepositoryInterface;
use HiEvents\Repository\Interfaces\SocialCommentRepositoryInterface;
use HiEvents\Repository\Interfaces\SocialFeedItemRepositoryInterface;
use HiEvents\Repository\Interfaces\SocialPostRepositoryInterface;
use HiEvents\Services\Domain\Social\Feed\SocialFeedSyncService;
use HiEvents\Services\Domain\Social\Media\SocialMediaFileService;
use HiEvents\Services\Domain\Social\MetaConnectionService;
use HiEvents\Services\Infrastructure\Meta\MetaGraphClient;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;

class SocialFeedSyncServiceTest extends TestCase
{
    private MetaGraphClient|MockInterface $client;

    private SocialFeedItemRepositoryInterface|MockInterface $itemRepository;

    private SocialCommentRepositoryInterface|MockInterface $commentRepository;

    private SocialPostRepositoryInterface|MockInterface $postRepository;

    private MetaConnectionService|MockInterface $connectionService;

    private SocialMediaFileService|MockInterface $mediaFileService;

    private SocialFeedSyncService $service;

    /** @var array<string, array> */
    private array $items = [];

    /** @var array<string, array> */
    private array $comments = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Mockery::mock(MetaGraphClient::class);
        $connections = Mockery::mock(OrganizerSocialConnectionRepositoryInterface::class);
        $connections->shouldReceive('findFirstWhere')->andReturn(
            (new OrganizerSocialConnectionDomainObject)
                ->setId(5)->setOrganizerId(4)->setAccountId(3)
                ->setStatus(SocialConnectionStatus::ACTIVE->name)
                ->setFacebookPageId('page-1')->setFacebookPageName('Deejay Innocent')
                ->setPageAccessToken('page-token')
                ->setInstagramAccountId('1784')->setInstagramUsername('dj_innocent976'),
        );
        $this->itemRepository = Mockery::mock(SocialFeedItemRepositoryInterface::class);
        $this->itemRepository->shouldReceive('findFirstWhere')->andReturnNull()->byDefault();
        $this->itemRepository->shouldReceive('upsertFromNetwork')->andReturnUsing(function (int $organizerId, string $network, string $externalId, array $attributes) {
            $this->items[$externalId] = $attributes;

            return (new SocialFeedItemDomainObject)->setId(count($this->items))->setOrganizerId($organizerId)->setNetwork($network)->setExternalId($externalId);
        });
        $this->itemRepository->shouldReceive('updateFromArray')->andReturn(new SocialFeedItemDomainObject);
        $this->commentRepository = Mockery::mock(SocialCommentRepositoryInterface::class);
        $this->commentRepository->shouldReceive('upsertFromNetwork')->andReturnUsing(function (string $network, string $externalId, array $attributes) {
            $this->comments[$externalId] = $attributes;

            return new SocialCommentDomainObject;
        });
        $this->commentRepository->shouldReceive('findForFeedItem')->andReturn(new Collection)->byDefault();
        $this->postRepository = Mockery::mock(SocialPostRepositoryInterface::class);
        $this->postRepository->shouldReceive('findFirstWhere')->andReturnNull()->byDefault();
        $this->connectionService = Mockery::mock(MetaConnectionService::class);
        $this->mediaFileService = Mockery::mock(SocialMediaFileService::class);

        $this->service = new SocialFeedSyncService(
            $this->client,
            $connections,
            $this->itemRepository,
            $this->commentRepository,
            $this->postRepository,
            $this->connectionService,
            $this->mediaFileService,
            new NullLogger,
        );
        $this->client->shouldReceive('getInstagramStories')->andReturn([])->byDefault();
    }

    public function test_a_new_story_is_copied_once_and_kept_out_of_the_feed(): void
    {
        $this->client->shouldReceive('getInstagramMedia')->andReturn([]);
        $this->client->shouldReceive('getPagePosts')->andReturn([]);
        $this->client->shouldReceive('getInstagramStories')->with('1784', 'page-token')->andReturn([
            ['id' => 'st-1', 'media_type' => 'VIDEO', 'media_url' => 'https://cdn.test/story.mp4', 'timestamp' => '2026-10-27T18:00:00+0000', 'permalink' => 'https://instagram.com/stories/1'],
            ['id' => 'st-2', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn.test/old.jpg', 'timestamp' => '2026-10-27T10:00:00+0000'],
        ]);
        $this->itemRepository->shouldReceive('findFirstWhere')->andReturnUsing(
            fn (array $where) => $where['external_id'] === 'st-2' ? new SocialFeedItemDomainObject : null,
        );
        $this->client->shouldReceive('downloadMedia')->once()->with('https://cdn.test/story.mp4', Mockery::type('string'));
        $this->mediaFileService->shouldReceive('storeCopy')->once()
            ->withArgs(fn (int $accountId, int $organizerId, string $path, string $name, $purgeAfter) => $accountId === 3 && $organizerId === 4
                && $name === 'story-st-1' && $purgeAfter->toDateString() === '2027-01-25')
            ->andReturn((new SocialMediaFileDomainObject)->setId(41));

        $this->service->syncOrganizer(4);

        $this->assertSame(['st-1'], array_keys($this->items));
        $this->assertSame('STORY', $this->items['st-1'][SocialFeedItemDomainObjectAbstract::MEDIA_PRODUCT_TYPE]);
        $this->assertSame([['file_id' => 41, 'type' => 'VIDEO']], $this->items['st-1'][SocialFeedItemDomainObjectAbstract::CHILDREN]);
    }

    public function test_a_story_that_cannot_be_downloaded_is_skipped_without_stopping_the_sync(): void
    {
        $this->client->shouldReceive('getInstagramMedia')->andReturn([]);
        $this->client->shouldReceive('getPagePosts')->andReturn([]);
        $this->client->shouldReceive('getInstagramStories')->andReturn([
            ['id' => 'st-1', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn.test/gone.jpg', 'timestamp' => '2026-10-27T18:00:00+0000'],
        ]);
        $this->client->shouldReceive('downloadMedia')->andThrow(new MetaGraphApiException('Media download failed', code: 404));
        $this->mediaFileService->shouldNotReceive('storeCopy');
        $this->connectionService->shouldNotReceive('markNeedsReconnect');

        $this->service->syncOrganizer(4);

        $this->assertSame([], $this->items);
    }

    public function test_instagram_and_facebook_posts_are_copied_with_their_media(): void
    {
        $this->client->shouldReceive('getInstagramMedia')->once()->with('1784', 'page-token', 25)->andReturn([
            [
                'id' => 'ig-1', 'media_type' => 'VIDEO', 'media_product_type' => 'REELS', 'caption' => 'Teaser',
                'media_url' => 'https://cdn.test/reel.mp4', 'thumbnail_url' => 'https://cdn.test/reel.jpg',
                'permalink' => 'https://instagram.com/p/1', 'timestamp' => '2026-10-24T16:00:00+0000', 'like_count' => 12, 'comments_count' => 0,
            ],
            [
                'id' => 'ig-2', 'media_type' => 'CAROUSEL_ALBUM', 'caption' => 'Photos', 'timestamp' => '2026-10-23T16:00:00+0000',
                'children' => ['data' => [
                    ['media_type' => 'IMAGE', 'media_url' => 'https://cdn.test/1.jpg'],
                    ['media_type' => 'VIDEO', 'media_url' => 'https://cdn.test/2.mp4', 'thumbnail_url' => 'https://cdn.test/2.jpg'],
                ]],
            ],
        ]);
        $this->client->shouldReceive('getPagePosts')->once()->andReturn([[
            'id' => 'page-1_p1', 'message' => 'Merci', 'created_time' => '2026-10-22T10:00:00+0000', 'permalink_url' => 'https://facebook.com/p1',
            'attachments' => ['data' => [['type' => 'photo', 'media_type' => 'photo', 'media' => ['image' => ['src' => 'https://cdn.test/fb.jpg']]]]],
            'reactions' => ['summary' => ['total_count' => 30]], 'comments' => ['summary' => ['total_count' => 0]],
        ]]);
        $this->postRepository->shouldReceive('findFirstWhere')->andReturnUsing(
            fn (array $where) => $where['external_id'] === 'ig-1' ? (new SocialPostDomainObject)->setId(77) : null,
        );

        $this->service->syncOrganizer(4);

        $this->assertSame('REELS', $this->items['ig-1'][SocialFeedItemDomainObjectAbstract::MEDIA_PRODUCT_TYPE]);
        $this->assertSame([['url' => 'https://cdn.test/reel.mp4', 'type' => 'VIDEO', 'thumbnail_url' => 'https://cdn.test/reel.jpg']], $this->items['ig-1'][SocialFeedItemDomainObjectAbstract::CHILDREN]);
        $this->assertSame(77, $this->items['ig-1'][SocialFeedItemDomainObjectAbstract::SOCIAL_POST_ID]);
        $this->assertCount(2, $this->items['ig-2'][SocialFeedItemDomainObjectAbstract::CHILDREN]);
        $this->assertSame('VIDEO', $this->items['ig-2'][SocialFeedItemDomainObjectAbstract::CHILDREN][1]['type']);
        $this->assertSame(30, $this->items['page-1_p1'][SocialFeedItemDomainObjectAbstract::LIKE_COUNT]);
        $this->assertSame('https://cdn.test/fb.jpg', $this->items['page-1_p1'][SocialFeedItemDomainObjectAbstract::MEDIA_URL]);
        $this->assertNull($this->items['page-1_p1'][SocialFeedItemDomainObjectAbstract::SOCIAL_POST_ID]);
    }

    public function test_comments_are_copied_with_replies_when_their_count_changes(): void
    {
        $this->client->shouldReceive('getInstagramMedia')->andReturn([[
            'id' => 'ig-1', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn.test/a.jpg', 'timestamp' => '2026-10-24T16:00:00+0000', 'comments_count' => 2,
        ]]);
        $this->client->shouldReceive('getPagePosts')->andReturn([]);
        $this->client->shouldReceive('getInstagramComments')->once()->with('ig-1', 'page-token')->andReturn([[
            'id' => 'c1', 'text' => 'On peut venir à quelle heure ?', 'username' => 'fan976', 'from' => ['id' => 'u1', 'username' => 'fan976'],
            'timestamp' => '2026-10-24T17:00:00+0000',
            'replies' => ['data' => [[
                'id' => 'r1', 'text' => 'Dès 21h !', 'from' => ['id' => '1784', 'username' => 'dj_innocent976'], 'timestamp' => '2026-10-24T17:05:00+0000',
            ]]],
        ]]);

        $this->service->syncOrganizer(4);

        $this->assertSame('fan976', $this->comments['c1'][SocialCommentDomainObjectAbstract::AUTHOR_NAME]);
        $this->assertFalse($this->comments['c1'][SocialCommentDomainObjectAbstract::IS_FROM_PAGE]);
        $this->assertNull($this->comments['c1'][SocialCommentDomainObjectAbstract::PARENT_EXTERNAL_ID]);
        $this->assertSame('c1', $this->comments['r1'][SocialCommentDomainObjectAbstract::PARENT_EXTERNAL_ID]);
        $this->assertTrue($this->comments['r1'][SocialCommentDomainObjectAbstract::IS_FROM_PAGE]);
    }

    public function test_comments_are_not_fetched_again_when_nothing_changed(): void
    {
        $this->itemRepository->shouldReceive('findFirstWhere')->andReturn(
            (new SocialFeedItemDomainObject)->setId(1)->setCommentsCount(2)->setCommentsSyncedAt('2026-10-24 17:00:00'),
        );
        $this->client->shouldReceive('getInstagramMedia')->andReturn([['id' => 'ig-1', 'media_type' => 'IMAGE', 'comments_count' => 2]]);
        $this->client->shouldReceive('getPagePosts')->andReturn([]);
        $this->client->shouldNotReceive('getInstagramComments');

        $this->service->syncOrganizer(4);

        $this->addToAssertionCount(1);
    }

    public function test_invalid_token_marks_the_connection_to_renew(): void
    {
        $this->client->shouldReceive('getInstagramMedia')->andThrow(new MetaGraphApiException('Session expired', code: 400, graphErrorCode: 190));
        $this->connectionService->shouldReceive('markNeedsReconnect')->once()->with(5, 'Session expired');

        $this->service->syncOrganizer(4);
    }
}

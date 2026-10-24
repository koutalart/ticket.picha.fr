<?php

namespace Tests\Unit\Services\Domain\Social;

use HiEvents\DomainObjects\Enums\SocialNetwork;
use HiEvents\DomainObjects\Enums\SocialPostFormat;
use HiEvents\DomainObjects\Generated\SocialPostDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSocialConnectionDomainObject;
use HiEvents\DomainObjects\SocialPostDomainObject;
use HiEvents\DomainObjects\Status\SocialConnectionStatus;
use HiEvents\DomainObjects\Status\SocialPostStatus;
use HiEvents\Exceptions\MetaGraphApiException;
use HiEvents\Repository\Interfaces\OrganizerSocialConnectionRepositoryInterface;
use HiEvents\Repository\Interfaces\SocialPostRepositoryInterface;
use HiEvents\Services\Domain\Social\MetaConnectionService;
use HiEvents\Services\Domain\Social\SocialPostService;
use HiEvents\Services\Domain\Social\SocialPostValidator;
use HiEvents\Services\Domain\Social\SocialPublishingService;
use HiEvents\Services\Infrastructure\Meta\MetaGraphClient;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;

class SocialPublishingServiceTest extends TestCase
{
    private const IG_ID = '1784';

    private const PAGE_ID = '258426924559094';

    private const TOKEN = 'page-token';

    private MetaGraphClient|MockInterface $client;

    private SocialPostRepositoryInterface|MockInterface $postRepository;

    private OrganizerSocialConnectionRepositoryInterface|MockInterface $connectionRepository;

    private MetaConnectionService|MockInterface $connectionService;

    private SocialPublishingService $service;

    /** @var array<int, array> */
    private array $updates = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Mockery::mock(MetaGraphClient::class);
        $this->postRepository = Mockery::mock(SocialPostRepositoryInterface::class);
        $this->connectionRepository = Mockery::mock(OrganizerSocialConnectionRepositoryInterface::class);
        $this->connectionService = Mockery::mock(MetaConnectionService::class);

        $this->postRepository->shouldReceive('updateFromArray')
            ->andReturnUsing(function (int $id, array $attributes) {
                $this->updates[] = $attributes;

                return new SocialPostDomainObject;
            });

        $this->service = new SocialPublishingService(
            $this->client,
            $this->postRepository,
            $this->connectionRepository,
            $this->connectionService,
            new SocialPostService($this->postRepository, $this->connectionService, new SocialPostValidator, app('db')),
            new NullLogger,
        );
    }

    public function test_instagram_image_is_published_once_the_container_is_ready(): void
    {
        $this->givenPost(SocialNetwork::INSTAGRAM, SocialPostFormat::IMAGE, [['url' => 'https://cdn.test/a.jpg', 'type' => 'IMAGE']]);
        $this->client->shouldReceive('getInstagramPublishingQuota')->once()->andReturn(['usage' => 3, 'total' => 100]);
        $this->client->shouldReceive('createInstagramContainer')->once()
            ->with(self::IG_ID, self::TOKEN, ['image_url' => 'https://cdn.test/a.jpg', 'caption' => 'Halloween'])
            ->andReturn('container-1');
        $this->client->shouldReceive('getInstagramContainerStatus')->once()->with('container-1', self::TOKEN)->andReturn('FINISHED');
        $this->client->shouldReceive('publishInstagramContainer')->once()->with(self::IG_ID, 'container-1', self::TOKEN)->andReturn('media-1');
        $this->client->shouldReceive('getPermalink')->once()->with('media-1', self::TOKEN, 'permalink')->andReturn('https://www.instagram.com/p/abc/');

        $result = $this->service->publish(1);

        $this->assertSame(SocialPostStatus::PUBLISHED, $result->status);
        $this->assertLastUpdate(SocialPostStatus::PUBLISHED, [
            SocialPostDomainObjectAbstract::EXTERNAL_ID => 'media-1',
            SocialPostDomainObjectAbstract::PERMALINK => 'https://www.instagram.com/p/abc/',
        ]);
    }

    public function test_instagram_reel_waits_while_the_video_is_processed(): void
    {
        $this->givenPost(SocialNetwork::INSTAGRAM, SocialPostFormat::REEL, [['url' => 'https://cdn.test/reel.mp4', 'type' => 'VIDEO']]);
        $this->client->shouldReceive('getInstagramPublishingQuota')->andReturn(null);
        $this->client->shouldReceive('createInstagramContainer')->once()
            ->with(self::IG_ID, self::TOKEN, ['media_type' => 'REELS', 'video_url' => 'https://cdn.test/reel.mp4', 'caption' => 'Halloween', 'share_to_feed' => 'true'])
            ->andReturn('container-2');
        $this->client->shouldReceive('getInstagramContainerStatus')->once()->andReturn('IN_PROGRESS');
        $this->client->shouldNotReceive('publishInstagramContainer');

        $result = $this->service->publish(1);

        $this->assertSame(SocialPostStatus::SCHEDULED, $result->status);
        $this->assertSame('container-2', $this->updates[0][SocialPostDomainObjectAbstract::EXTERNAL_CONTAINER_ID]);
        $this->assertLastUpdate(SocialPostStatus::SCHEDULED);
        $this->assertArrayNotHasKey(SocialPostDomainObjectAbstract::ATTEMPTS, end($this->updates));
    }

    public function test_instagram_reuses_the_container_already_created(): void
    {
        $this->givenPost(SocialNetwork::INSTAGRAM, SocialPostFormat::REEL, [['url' => 'https://cdn.test/reel.mp4', 'type' => 'VIDEO']], containerId: 'container-2');
        $this->client->shouldNotReceive('createInstagramContainer');
        $this->client->shouldNotReceive('getInstagramPublishingQuota');
        $this->client->shouldReceive('getInstagramContainerStatus')->once()->andReturn('FINISHED');
        $this->client->shouldReceive('publishInstagramContainer')->once()->andReturn('media-2');
        $this->client->shouldReceive('getPermalink')->andThrow(new MetaGraphApiException('Unsupported'));

        $this->assertSame(SocialPostStatus::PUBLISHED, $this->service->publish(1)->status);
        $this->assertLastUpdate(SocialPostStatus::PUBLISHED, [SocialPostDomainObjectAbstract::PERMALINK => null]);
    }

    public function test_instagram_story_with_a_video_or_an_image(): void
    {
        $this->givenPost(SocialNetwork::INSTAGRAM, SocialPostFormat::STORY, [['url' => 'https://cdn.test/story.mp4', 'type' => 'VIDEO']]);
        $this->client->shouldReceive('getInstagramPublishingQuota')->andReturn(null);
        $this->client->shouldReceive('createInstagramContainer')->once()
            ->with(self::IG_ID, self::TOKEN, ['media_type' => 'STORIES', 'video_url' => 'https://cdn.test/story.mp4'])
            ->andReturn('container-3');
        $this->client->shouldReceive('getInstagramContainerStatus')->andReturn('FINISHED');
        $this->client->shouldReceive('publishInstagramContainer')->andReturn('media-3');
        $this->client->shouldReceive('getPermalink')->andReturn(null);

        $this->assertSame(SocialPostStatus::PUBLISHED, $this->service->publish(1)->status);

        $this->givenPost(SocialNetwork::INSTAGRAM, SocialPostFormat::STORY, [['url' => 'https://cdn.test/story.jpg', 'type' => 'IMAGE']], postId: 2);
        $this->client->shouldReceive('createInstagramContainer')->once()
            ->with(self::IG_ID, self::TOKEN, ['media_type' => 'STORIES', 'image_url' => 'https://cdn.test/story.jpg'])
            ->andReturn('container-4');

        $this->assertSame(SocialPostStatus::PUBLISHED, $this->service->publish(2)->status);
    }

    public function test_instagram_carousel_creates_one_child_per_image(): void
    {
        $this->givenPost(SocialNetwork::INSTAGRAM, SocialPostFormat::CAROUSEL, [
            ['url' => 'https://cdn.test/1.jpg', 'type' => 'IMAGE'],
            ['url' => 'https://cdn.test/2.jpg', 'type' => 'IMAGE'],
        ]);
        $this->client->shouldReceive('getInstagramPublishingQuota')->andReturn(null);
        $this->client->shouldReceive('createInstagramContainer')->once()
            ->with(self::IG_ID, self::TOKEN, ['image_url' => 'https://cdn.test/1.jpg', 'is_carousel_item' => 'true'])->andReturn('child-1');
        $this->client->shouldReceive('createInstagramContainer')->once()
            ->with(self::IG_ID, self::TOKEN, ['image_url' => 'https://cdn.test/2.jpg', 'is_carousel_item' => 'true'])->andReturn('child-2');
        $this->client->shouldReceive('createInstagramContainer')->once()
            ->with(self::IG_ID, self::TOKEN, ['media_type' => 'CAROUSEL', 'caption' => 'Halloween', 'children' => 'child-1,child-2'])
            ->andReturn('carousel-1');
        $this->client->shouldReceive('getInstagramContainerStatus')->with('carousel-1', self::TOKEN)->andReturn('FINISHED');
        $this->client->shouldReceive('publishInstagramContainer')->with(self::IG_ID, 'carousel-1', self::TOKEN)->andReturn('media-4');
        $this->client->shouldReceive('getPermalink')->andReturn(null);

        $this->assertSame(SocialPostStatus::PUBLISHED, $this->service->publish(1)->status);
    }

    public function test_instagram_container_error_fails_the_post(): void
    {
        $this->givenPost(SocialNetwork::INSTAGRAM, SocialPostFormat::REEL, [['url' => 'https://cdn.test/reel.mp4', 'type' => 'VIDEO']], containerId: 'container-2');
        $this->client->shouldReceive('getInstagramContainerStatus')->andReturn('ERROR');

        $this->assertSame(SocialPostStatus::FAILED, $this->service->publish(1)->status);
        $this->assertLastUpdate(SocialPostStatus::FAILED);
    }

    public function test_instagram_quota_reached_postpones_without_counting_an_attempt(): void
    {
        $this->givenPost(SocialNetwork::INSTAGRAM, SocialPostFormat::IMAGE, [['url' => 'https://cdn.test/a.jpg', 'type' => 'IMAGE']]);
        $this->client->shouldReceive('getInstagramPublishingQuota')->once()->andReturn(['usage' => 100, 'total' => 100]);
        $this->client->shouldNotReceive('createInstagramContainer');

        $result = $this->service->publish(1);

        $this->assertSame(SocialPostStatus::SCHEDULED, $result->status);
        $update = end($this->updates);
        $this->assertArrayNotHasKey(SocialPostDomainObjectAbstract::ATTEMPTS, $update);
        $this->assertTrue($update[SocialPostDomainObjectAbstract::NEXT_ATTEMPT_AT]->greaterThan(now()->addMinutes(59)));
    }

    public function test_facebook_text_post_with_link(): void
    {
        $this->givenPost(SocialNetwork::FACEBOOK, SocialPostFormat::TEXT, [], link: 'https://ticket.picha.fr/e/1/halloween');
        $this->client->shouldReceive('publishPageFeedPost')->once()
            ->with(self::PAGE_ID, self::TOKEN, 'Halloween', 'https://ticket.picha.fr/e/1/halloween')
            ->andReturn('page_post-1');
        $this->client->shouldReceive('getPermalink')->with('page_post-1', self::TOKEN, 'permalink_url')->andReturn('https://www.facebook.com/post-1');

        $this->assertSame(SocialPostStatus::PUBLISHED, $this->service->publish(1)->status);
        $this->assertLastUpdate(SocialPostStatus::PUBLISHED, [SocialPostDomainObjectAbstract::EXTERNAL_ID => 'page_post-1']);
    }

    public function test_facebook_image_uses_the_post_id_of_the_photo(): void
    {
        $this->givenPost(SocialNetwork::FACEBOOK, SocialPostFormat::IMAGE, [['url' => 'https://cdn.test/a.png', 'type' => 'IMAGE']]);
        $this->client->shouldReceive('publishPagePhoto')->once()
            ->with(self::PAGE_ID, self::TOKEN, 'https://cdn.test/a.png', 'Halloween')
            ->andReturn(['id' => 'photo-1', 'post_id' => 'page_post-2']);
        $this->client->shouldReceive('getPermalink')->andReturn(null);

        $this->service->publish(1);

        $this->assertLastUpdate(SocialPostStatus::PUBLISHED, [SocialPostDomainObjectAbstract::EXTERNAL_ID => 'page_post-2']);
    }

    public function test_facebook_carousel_attaches_unpublished_photos_to_one_post(): void
    {
        $this->givenPost(SocialNetwork::FACEBOOK, SocialPostFormat::CAROUSEL, [
            ['url' => 'https://cdn.test/1.jpg', 'type' => 'IMAGE'],
            ['url' => 'https://cdn.test/2.jpg', 'type' => 'IMAGE'],
        ]);
        $this->client->shouldReceive('publishPagePhoto')->once()->with(self::PAGE_ID, self::TOKEN, 'https://cdn.test/1.jpg', null, false)->andReturn(['id' => 'p1', 'post_id' => null]);
        $this->client->shouldReceive('publishPagePhoto')->once()->with(self::PAGE_ID, self::TOKEN, 'https://cdn.test/2.jpg', null, false)->andReturn(['id' => 'p2', 'post_id' => null]);
        $this->client->shouldReceive('publishPageFeedPost')->once()->with(self::PAGE_ID, self::TOKEN, 'Halloween', null, ['p1', 'p2'])->andReturn('page_post-3');
        $this->client->shouldReceive('getPermalink')->andReturn(null);

        $this->assertSame(SocialPostStatus::PUBLISHED, $this->service->publish(1)->status);
    }

    public function test_facebook_reel_and_video_story(): void
    {
        $this->givenPost(SocialNetwork::FACEBOOK, SocialPostFormat::REEL, [['url' => 'https://cdn.test/reel.mp4', 'type' => 'VIDEO']]);
        $this->client->shouldReceive('publishPageVideo')->once()->with(self::PAGE_ID, self::TOKEN, 'video_reels', 'https://cdn.test/reel.mp4', 'Halloween')->andReturn('reel-1');
        $this->client->shouldReceive('getPermalink')->andReturn(null);

        $this->assertSame(SocialPostStatus::PUBLISHED, $this->service->publish(1)->status);

        $this->givenPost(SocialNetwork::FACEBOOK, SocialPostFormat::STORY, [['url' => 'https://cdn.test/story.mp4', 'type' => 'VIDEO']], postId: 2);
        $this->client->shouldReceive('publishPageVideo')->once()->with(self::PAGE_ID, self::TOKEN, 'video_stories', 'https://cdn.test/story.mp4')->andReturn('story-1');

        $this->assertSame(SocialPostStatus::PUBLISHED, $this->service->publish(2)->status);
    }

    public function test_facebook_photo_story(): void
    {
        $this->givenPost(SocialNetwork::FACEBOOK, SocialPostFormat::STORY, [['url' => 'https://cdn.test/story.jpg', 'type' => 'IMAGE']]);
        $this->client->shouldReceive('publishPagePhoto')->once()->with(self::PAGE_ID, self::TOKEN, 'https://cdn.test/story.jpg', null, false)->andReturn(['id' => 'photo-9', 'post_id' => null]);
        $this->client->shouldReceive('publishPagePhotoStory')->once()->with(self::PAGE_ID, self::TOKEN, 'photo-9')->andReturn('story-2');
        $this->client->shouldReceive('getPermalink')->andReturn(null);

        $this->assertSame(SocialPostStatus::PUBLISHED, $this->service->publish(1)->status);
    }

    public function test_invalid_token_marks_the_connection_and_fails_the_post(): void
    {
        $this->givenPost(SocialNetwork::FACEBOOK, SocialPostFormat::TEXT, []);
        $this->client->shouldReceive('publishPageFeedPost')->andThrow(new MetaGraphApiException('Session has expired', code: 400, graphErrorCode: 190));
        $this->connectionService->shouldReceive('markNeedsReconnect')->once()->with(5, 'Session has expired');

        $this->assertSame(SocialPostStatus::FAILED, $this->service->publish(1)->status);
        $this->assertLastUpdate(SocialPostStatus::FAILED);
    }

    public function test_temporary_error_is_retried_and_counted(): void
    {
        $this->givenPost(SocialNetwork::FACEBOOK, SocialPostFormat::TEXT, []);
        $this->client->shouldReceive('publishPageFeedPost')->andThrow(new MetaGraphApiException('Service unavailable', retryable: true, code: 503));

        $this->assertSame(SocialPostStatus::SCHEDULED, $this->service->publish(1)->status);
        $this->assertSame(1, end($this->updates)[SocialPostDomainObjectAbstract::ATTEMPTS]);
    }

    public function test_temporary_error_fails_after_the_last_attempt(): void
    {
        $this->givenPost(SocialNetwork::FACEBOOK, SocialPostFormat::TEXT, [], attempts: SocialPublishingService::MAX_ATTEMPTS - 1);
        $this->client->shouldReceive('publishPageFeedPost')->andThrow(new MetaGraphApiException('Service unavailable', retryable: true, code: 503));

        $this->assertSame(SocialPostStatus::FAILED, $this->service->publish(1)->status);
    }

    public function test_connection_to_renew_fails_without_calling_meta(): void
    {
        $this->givenPost(SocialNetwork::FACEBOOK, SocialPostFormat::TEXT, [], connectionStatus: SocialConnectionStatus::NEEDS_RECONNECT);
        $this->client->shouldNotReceive('publishPageFeedPost');

        $this->assertSame(SocialPostStatus::FAILED, $this->service->publish(1)->status);
    }

    public function test_post_no_longer_publishing_is_ignored(): void
    {
        $this->postRepository->shouldReceive('findFirstWhere')->with([SocialPostDomainObjectAbstract::ID => 1])->andReturn(
            (new SocialPostDomainObject)->setId(1)->setStatus(SocialPostStatus::CANCELLED->name),
        );

        $this->assertSame(SocialPostStatus::CANCELLED, $this->service->publish(1)->status);
        $this->assertSame([], $this->updates);
    }

    private function givenPost(
        SocialNetwork $network,
        SocialPostFormat $format,
        array $media,
        ?string $link = null,
        ?string $containerId = null,
        int $attempts = 0,
        int $postId = 1,
        SocialConnectionStatus $connectionStatus = SocialConnectionStatus::ACTIVE,
    ): void {
        $post = (new SocialPostDomainObject)
            ->setId($postId)
            ->setOrganizerId(4)
            ->setAccountId(3)
            ->setNetwork($network->name)
            ->setFormat($format->name)
            ->setCaption('Halloween')
            ->setMedia($media)
            ->setLink($link)
            ->setStatus(SocialPostStatus::PUBLISHING->name)
            ->setExternalContainerId($containerId)
            ->setAttempts($attempts);

        $this->postRepository->shouldReceive('findFirstWhere')->with([SocialPostDomainObjectAbstract::ID => $postId])->andReturn($post);
        $this->connectionRepository->shouldReceive('findFirstWhere')->andReturn(
            (new OrganizerSocialConnectionDomainObject)
                ->setId(5)
                ->setStatus($connectionStatus->name)
                ->setFacebookPageId(self::PAGE_ID)
                ->setPageAccessToken(self::TOKEN)
                ->setInstagramAccountId(self::IG_ID),
        );
    }

    private function assertLastUpdate(SocialPostStatus $status, array $expected = []): void
    {
        $update = end($this->updates);

        $this->assertSame($status->name, $update[SocialPostDomainObjectAbstract::STATUS]);

        foreach ($expected as $key => $value) {
            $this->assertSame($value, $update[$key], $key);
        }
    }
}

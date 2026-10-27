<?php

namespace Tests\Unit\Services\Domain\Social\Feed;

use HiEvents\DomainObjects\Generated\SocialCommentDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSocialConnectionDomainObject;
use HiEvents\DomainObjects\SocialCommentDomainObject;
use HiEvents\Exceptions\MetaGraphApiException;
use HiEvents\Exceptions\SocialCommentReplyFailedException;
use HiEvents\Repository\Interfaces\SocialCommentRepositoryInterface;
use HiEvents\Repository\Interfaces\SocialFeedItemRepositoryInterface;
use HiEvents\Services\Domain\Social\Feed\SocialCommentService;
use HiEvents\Services\Domain\Social\Feed\SocialFeedSyncService;
use HiEvents\Services\Domain\Social\MetaConnectionService;
use HiEvents\Services\Infrastructure\Meta\MetaGraphClient;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SocialCommentServiceTest extends TestCase
{
    private SocialCommentRepositoryInterface|MockInterface $commentRepository;

    private SocialFeedSyncService|MockInterface $syncService;

    private MetaConnectionService|MockInterface $connectionService;

    private MetaGraphClient|MockInterface $client;

    private SocialCommentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->commentRepository = Mockery::mock(SocialCommentRepositoryInterface::class);
        $this->syncService = Mockery::mock(SocialFeedSyncService::class);
        $this->syncService->shouldReceive('activeConnection')->andReturn(
            (new OrganizerSocialConnectionDomainObject)->setId(5)->setPageAccessToken('page-token')
                ->setInstagramAccountId('1784')->setInstagramUsername('dj_innocent976')
                ->setFacebookPageId('page-1')->setFacebookPageName('Deejay Innocent'),
        )->byDefault();
        $this->connectionService = Mockery::mock(MetaConnectionService::class);
        $this->client = Mockery::mock(MetaGraphClient::class);

        $this->service = new SocialCommentService(
            Mockery::mock(SocialFeedItemRepositoryInterface::class),
            $this->commentRepository,
            $this->syncService,
            $this->connectionService,
            $this->client,
        );
    }

    public function test_reply_to_an_instagram_reply_goes_to_its_thread_and_is_stored_as_the_page(): void
    {
        $this->givenComment('INSTAGRAM', 'r1', 'c1');
        $this->client->shouldReceive('replyToInstagramComment')->once()->with('c1', 'page-token', 'Dès 21h !')->andReturn('r2');
        $this->commentRepository->shouldReceive('upsertFromNetwork')->once()
            ->withArgs(fn (string $network, string $id, array $attributes) => $id === 'r2'
                && $attributes[SocialCommentDomainObjectAbstract::PARENT_EXTERNAL_ID] === 'c1'
                && $attributes[SocialCommentDomainObjectAbstract::IS_FROM_PAGE] === true
                && $attributes[SocialCommentDomainObjectAbstract::AUTHOR_NAME] === 'dj_innocent976'
                && $attributes[SocialCommentDomainObjectAbstract::REPLIED_BY_USER_ID] === 7)
            ->andReturn(new SocialCommentDomainObject);

        $this->service->reply(4, 11, 7, "  Dès 21h !\r\n");
    }

    public function test_reply_to_a_facebook_comment(): void
    {
        $this->givenComment('FACEBOOK', 'page-1_p1_c1', null);
        $this->client->shouldReceive('replyToFacebookComment')->once()->with('page-1_p1_c1', 'page-token', 'Merci !')->andReturn('page-1_p1_r1');
        $this->commentRepository->shouldReceive('upsertFromNetwork')->once()->andReturn(new SocialCommentDomainObject);

        $this->service->reply(4, 11, 7, 'Merci !');
    }

    public function test_empty_reply_is_refused_without_calling_meta(): void
    {
        $this->givenComment('INSTAGRAM', 'c1', null);
        $this->client->shouldNotReceive('replyToInstagramComment');

        $this->expectException(SocialCommentReplyFailedException::class);
        $this->service->reply(4, 11, 7, '   ');
    }

    public function test_invalid_token_marks_the_connection_and_reports_the_failure(): void
    {
        $this->givenComment('INSTAGRAM', 'c1', null);
        $this->client->shouldReceive('replyToInstagramComment')->andThrow(new MetaGraphApiException('Session expired', code: 400, graphErrorCode: 190));
        $this->connectionService->shouldReceive('markNeedsReconnect')->once()->with(5, 'Session expired');
        $this->commentRepository->shouldNotReceive('upsertFromNetwork');

        $this->expectException(SocialCommentReplyFailedException::class);
        $this->service->reply(4, 11, 7, 'Merci');
    }

    public function test_reply_needs_an_active_connection(): void
    {
        $this->givenComment('INSTAGRAM', 'c1', null);
        $this->syncService->shouldReceive('activeConnection')->andReturnNull();

        $this->expectException(SocialCommentReplyFailedException::class);
        $this->service->reply(4, 11, 7, 'Merci');
    }

    private function givenComment(string $network, string $externalId, ?string $parentId): void
    {
        $this->commentRepository->shouldReceive('findFirstWhere')
            ->with([SocialCommentDomainObjectAbstract::ID => 11, SocialCommentDomainObjectAbstract::ORGANIZER_ID => 4])
            ->andReturn((new SocialCommentDomainObject)->setId(11)->setNetwork($network)->setExternalId($externalId)
                ->setParentExternalId($parentId)->setSocialFeedItemId(9));
    }
}

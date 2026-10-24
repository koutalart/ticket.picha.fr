<?php

namespace Tests\Unit\Services\Domain\Social\Feed;

use HiEvents\DomainObjects\Generated\SocialCommentDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSocialConnectionDomainObject;
use HiEvents\DomainObjects\SocialCommentDomainObject;
use HiEvents\DomainObjects\SocialFeedItemDomainObject;
use HiEvents\Jobs\Social\SyncSocialFeedJob;
use HiEvents\Repository\Interfaces\OrganizerSocialConnectionRepositoryInterface;
use HiEvents\Repository\Interfaces\SocialCommentRepositoryInterface;
use HiEvents\Repository\Interfaces\SocialFeedItemRepositoryInterface;
use HiEvents\Services\Domain\Social\Feed\MetaWebhookService;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class MetaWebhookServiceTest extends TestCase
{
    private SocialFeedItemRepositoryInterface|MockInterface $itemRepository;

    private SocialCommentRepositoryInterface|MockInterface $commentRepository;

    private MetaWebhookService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        config(['services.meta.app_secret' => 'app-secret', 'services.meta.webhook_verify_token' => 'verify-me']);

        $connections = Mockery::mock(OrganizerSocialConnectionRepositoryInterface::class);
        $connections->shouldReceive('findFirstWhere')->andReturnUsing(fn (array $where) => in_array(reset($where), ['1784', 'page-1'], true)
            ? (new OrganizerSocialConnectionDomainObject)->setOrganizerId(4)->setFacebookPageId('page-1')->setInstagramAccountId('1784')->setInstagramUsername('dj_innocent976')
            : null);
        $this->itemRepository = Mockery::mock(SocialFeedItemRepositoryInterface::class);
        $this->commentRepository = Mockery::mock(SocialCommentRepositoryInterface::class);

        $this->service = new MetaWebhookService(app('config'), $connections, $this->itemRepository, $this->commentRepository);
    }

    public function test_subscription_challenge_needs_the_verify_token(): void
    {
        $this->assertSame('1158201444', $this->service->verifyChallenge('subscribe', 'verify-me', '1158201444'));
        $this->assertNull($this->service->verifyChallenge('subscribe', 'wrong', '1158201444'));
        $this->assertNull($this->service->verifyChallenge('unsubscribe', 'verify-me', '1158201444'));
    }

    public function test_signature_is_checked_against_the_app_secret(): void
    {
        $body = '{"object":"page","entry":[]}';

        $this->assertTrue($this->service->hasValidSignature($body, 'sha256='.hash_hmac('sha256', $body, 'app-secret')));
        $this->assertFalse($this->service->hasValidSignature($body, 'sha256='.hash_hmac('sha256', $body, 'other-secret')));
        $this->assertFalse($this->service->hasValidSignature($body, null));
    }

    public function test_instagram_comment_is_stored_at_once(): void
    {
        $this->itemRepository->shouldReceive('findFirstWhere')->andReturn((new SocialFeedItemDomainObject)->setId(9));
        $this->commentRepository->shouldReceive('upsertFromNetwork')->once()
            ->withArgs(fn (string $network, string $id, array $attributes) => $network === 'INSTAGRAM' && $id === 'c-77'
                && $attributes[SocialCommentDomainObjectAbstract::SOCIAL_FEED_ITEM_ID] === 9
                && $attributes[SocialCommentDomainObjectAbstract::MESSAGE] === 'Il reste des places ?'
                && $attributes[SocialCommentDomainObjectAbstract::AUTHOR_NAME] === 'fan976'
                && $attributes[SocialCommentDomainObjectAbstract::IS_FROM_PAGE] === false)
            ->andReturn(new SocialCommentDomainObject);

        $this->service->handle([
            'object' => 'instagram',
            'entry' => [['id' => '1784', 'changes' => [[
                'field' => 'comments',
                'value' => ['id' => 'c-77', 'text' => 'Il reste des places ?', 'from' => ['id' => 'u1', 'username' => 'fan976'], 'media' => ['id' => 'ig-1']],
            ]]]],
        ]);

        Bus::assertNotDispatched(SyncSocialFeedJob::class);
    }

    public function test_removed_facebook_comment_is_deleted(): void
    {
        $this->commentRepository->shouldReceive('deleteFromNetwork')->once()->with('FACEBOOK', 'page-1_p1_c1');

        $this->service->handle([
            'object' => 'page',
            'entry' => [['id' => 'page-1', 'changes' => [[
                'field' => 'feed',
                'value' => ['item' => 'comment', 'verb' => 'remove', 'comment_id' => 'page-1_p1_c1', 'post_id' => 'page-1_p1'],
            ]]]],
        ]);
    }

    public function test_comment_on_an_unknown_post_or_a_new_post_triggers_a_sync(): void
    {
        $this->itemRepository->shouldReceive('findFirstWhere')->andReturnNull();

        $this->service->handle([
            'object' => 'page',
            'entry' => [['id' => 'page-1', 'changes' => [
                ['field' => 'feed', 'value' => ['item' => 'comment', 'verb' => 'add', 'comment_id' => 'x', 'post_id' => 'page-1_new']],
                ['field' => 'feed', 'value' => ['item' => 'status', 'verb' => 'add', 'post_id' => 'page-1_new']],
            ]]],
        ]);

        Bus::assertDispatched(SyncSocialFeedJob::class, fn (SyncSocialFeedJob $job) => $job->organizerId === 4);
    }

    public function test_events_of_an_unknown_account_are_ignored(): void
    {
        $this->service->handle(['object' => 'page', 'entry' => [['id' => 'someone-else', 'changes' => [['field' => 'feed', 'value' => []]]]]]);

        Bus::assertNothingDispatched();
    }
}

<?php

namespace Tests\Unit\Services\Domain\Social;

use HiEvents\DomainObjects\Generated\OrganizerSocialConnectionDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSocialConnectionDomainObject;
use HiEvents\DomainObjects\Status\SocialConnectionStatus;
use HiEvents\Exceptions\MetaGraphApiException;
use HiEvents\Exceptions\MetaOAuthException;
use HiEvents\Jobs\Social\SyncSocialFeedJob;
use HiEvents\Repository\Interfaces\OrganizerSocialConnectionRepositoryInterface;
use HiEvents\Repository\Interfaces\SocialFeedItemRepositoryInterface;
use HiEvents\Services\Domain\Social\MetaConnectionService;
use HiEvents\Services\Infrastructure\Encryption\EncryptedPayloadService;
use HiEvents\Services\Infrastructure\Meta\DTO\MetaAccessTokenDTO;
use HiEvents\Services\Infrastructure\Meta\DTO\MetaPageDTO;
use HiEvents\Services\Infrastructure\Meta\MetaGraphClient;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;

class MetaConnectionServiceTest extends TestCase
{
    private MetaGraphClient|MockInterface $client;

    private OrganizerSocialConnectionRepositoryInterface|MockInterface $repository;

    private CacheRepository $cache;

    private SocialFeedItemRepositoryInterface|MockInterface $feedItemRepository;

    private MetaConnectionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.api_url' => 'https://api.example.test', 'app.frontend_url' => 'https://app.example.test']);

        Bus::fake();
        $this->client = Mockery::mock(MetaGraphClient::class);
        $this->client->shouldReceive('subscribePageToWebhooks')->byDefault();
        $this->repository = Mockery::mock(OrganizerSocialConnectionRepositoryInterface::class);
        $this->cache = new CacheRepository(new ArrayStore);
        $this->feedItemRepository = Mockery::mock(SocialFeedItemRepositoryInterface::class);

        $this->service = new MetaConnectionService(
            $this->client,
            $this->repository,
            new EncryptedPayloadService(app('encrypter')),
            $this->cache,
            app('encrypter'),
            new NullLogger,
            $this->feedItemRepository,
        );
    }

    public function test_login_url_carries_an_encrypted_state_and_the_callback(): void
    {
        $this->client->shouldReceive('buildLoginUrl')
            ->once()
            ->withArgs(function (string $redirectUri, string $state) {
                $payload = $this->service->readState($state);

                return $redirectUri === 'https://api.example.test/public/social/meta/callback'
                    && $payload['account_id'] === 3 && $payload['organizer_id'] === 4 && $payload['user_id'] === 7;
            })
            ->andReturn('https://www.facebook.com/dialog');

        $this->assertSame('https://www.facebook.com/dialog', $this->service->getLoginUrl(3, 4, 7));
    }

    public function test_tampered_state_is_refused(): void
    {
        $this->expectException(MetaOAuthException::class);

        $this->service->readState('not-a-valid-state');
    }

    public function test_callback_keeps_the_pages_for_the_choice_without_exposing_tokens(): void
    {
        $this->mockSuccessfulOAuth();

        $result = $this->service->handleCallback('code', $this->state());

        $this->assertSame(4, $result->organizerId);
        $this->assertSame(40, strlen($result->selectionKey));
        $this->assertStringNotContainsString('page-token', (string) $this->cache->get('social:meta:selection:'.$result->selectionKey));

        $pages = $this->service->getPendingPages(3, 4, $result->selectionKey);
        $this->assertCount(2, $pages);
        $this->assertSame('Deejay Innocent', $pages[0]->name);
    }

    public function test_callback_failure_from_meta_becomes_an_oauth_error(): void
    {
        $this->client->shouldReceive('exchangeCodeForToken')->andThrow(new MetaGraphApiException('Invalid verification code format.', code: 400, graphErrorCode: 100));

        $this->expectException(MetaOAuthException::class);

        $this->service->handleCallback('bad-code', $this->state());
    }

    public function test_pending_pages_are_refused_to_another_organizer(): void
    {
        $this->mockSuccessfulOAuth();
        $result = $this->service->handleCallback('code', $this->state());

        $this->expectException(MetaOAuthException::class);

        $this->service->getPendingPages(3, 99, $result->selectionKey);
    }

    public function test_connect_saves_the_chosen_page_with_its_instagram_account(): void
    {
        $this->mockSuccessfulOAuth();
        $result = $this->service->handleCallback('code', $this->state());

        $this->repository->shouldReceive('findFirstWhere')->once()->andReturnNull();
        $this->repository->shouldReceive('create')
            ->once()
            ->withArgs(fn (array $attributes) => $attributes[OrganizerSocialConnectionDomainObjectAbstract::ORGANIZER_ID] === 4
                && $attributes[OrganizerSocialConnectionDomainObjectAbstract::ACCOUNT_ID] === 3
                && $attributes[OrganizerSocialConnectionDomainObjectAbstract::STATUS] === SocialConnectionStatus::ACTIVE->name
                && $attributes[OrganizerSocialConnectionDomainObjectAbstract::FACEBOOK_PAGE_ID] === '258426924559094'
                && $attributes[OrganizerSocialConnectionDomainObjectAbstract::PAGE_ACCESS_TOKEN] === 'page-token'
                && $attributes[OrganizerSocialConnectionDomainObjectAbstract::USER_ACCESS_TOKEN] === 'long-token'
                && $attributes[OrganizerSocialConnectionDomainObjectAbstract::INSTAGRAM_ACCOUNT_ID] === '1784'
                && $attributes[OrganizerSocialConnectionDomainObjectAbstract::CONNECTED_BY_USER_ID] === 7)
            ->andReturn((new OrganizerSocialConnectionDomainObject)->setId(1));

        $this->client->shouldReceive('subscribePageToWebhooks')->once()->with('258426924559094', 'page-token');

        $this->service->connect(3, 4, $result->selectionKey, '258426924559094');

        $this->assertNull($this->cache->get('social:meta:selection:'.$result->selectionKey));
        Bus::assertDispatched(SyncSocialFeedJob::class, fn (SyncSocialFeedJob $job) => $job->organizerId === 4);
    }

    public function test_connect_replaces_the_existing_connection(): void
    {
        $this->mockSuccessfulOAuth();
        $result = $this->service->handleCallback('code', $this->state());

        $this->repository->shouldReceive('findFirstWhere')->once()->andReturn((new OrganizerSocialConnectionDomainObject)->setId(12)->setFacebookPageId('old-page'));
        $this->feedItemRepository->shouldReceive('deleteWhere')->once()->with(['organizer_id' => 4]);
        $this->repository->shouldReceive('updateFromArray')
            ->once()
            ->withArgs(fn (int $id, array $attributes) => $id === 12 && $attributes[OrganizerSocialConnectionDomainObjectAbstract::LAST_ERROR] === null)
            ->andReturn((new OrganizerSocialConnectionDomainObject)->setId(12));
        $this->repository->shouldNotReceive('create');

        $this->service->connect(3, 4, $result->selectionKey, '258426924559094');
    }

    public function test_connect_refuses_a_page_without_publishing_rights(): void
    {
        $this->mockSuccessfulOAuth();
        $result = $this->service->handleCallback('code', $this->state());
        $this->repository->shouldNotReceive('create');

        $this->expectException(MetaOAuthException::class);

        $this->service->connect(3, 4, $result->selectionKey, '555');
    }

    public function test_connect_refuses_a_page_that_was_not_offered(): void
    {
        $this->mockSuccessfulOAuth();
        $result = $this->service->handleCallback('code', $this->state());

        $this->expectException(MetaOAuthException::class);

        $this->service->connect(3, 4, $result->selectionKey, 'unknown-page');
    }

    public function test_connect_refuses_an_expired_selection(): void
    {
        $this->expectException(MetaOAuthException::class);

        $this->service->connect(3, 4, str_repeat('a', 40), '258426924559094');
    }

    public function test_disconnect_revokes_permissions_and_deletes_even_if_meta_refuses(): void
    {
        $connection = (new OrganizerSocialConnectionDomainObject)
            ->setId(12)
            ->setFacebookUserId('fb-user')
            ->setUserAccessToken('long-token');

        $this->repository->shouldReceive('findFirstWhere')->once()->andReturn($connection);
        $this->client->shouldReceive('revokePermissions')->once()->with('fb-user', 'long-token')->andThrow(new MetaGraphApiException('Expired', graphErrorCode: 190));
        $this->repository->shouldReceive('deleteById')->once()->with(12);
        $this->feedItemRepository->shouldReceive('deleteWhere')->once()->with(['organizer_id' => 4])->andReturn(5);

        $this->service->disconnect(3, 4);
    }

    public function test_needs_reconnect_keeps_the_error(): void
    {
        $this->repository->shouldReceive('updateFromArray')
            ->once()
            ->withArgs(fn (int $id, array $attributes) => $id === 12
                && $attributes[OrganizerSocialConnectionDomainObjectAbstract::STATUS] === SocialConnectionStatus::NEEDS_RECONNECT->name
                && $attributes[OrganizerSocialConnectionDomainObjectAbstract::LAST_ERROR] === 'Session invalidated')
            ->andReturn(new OrganizerSocialConnectionDomainObject);

        $this->service->markNeedsReconnect(12, 'Session invalidated');
    }

    public function test_settings_url_points_to_the_social_section(): void
    {
        $this->assertSame(
            'https://app.example.test/manage/organizer/4/settings?social_selection=abc#social',
            $this->service->getSettingsUrl(4, ['social_selection' => 'abc']),
        );
    }

    private function state(): string
    {
        return (new EncryptedPayloadService(app('encrypter')))->encryptPayload([
            'account_id' => 3,
            'organizer_id' => 4,
            'user_id' => 7,
        ]);
    }

    private function mockSuccessfulOAuth(): void
    {
        $this->client->shouldReceive('exchangeCodeForToken')->andReturn(new MetaAccessTokenDTO('short-token', 3600));
        $this->client->shouldReceive('exchangeForLongLivedToken')->with('short-token')->andReturn(new MetaAccessTokenDTO('long-token', 5184000));
        $this->client->shouldReceive('getUserId')->with('long-token')->andReturn('fb-user');
        $this->client->shouldReceive('getGrantedScopes')->with('long-token')->andReturn(['pages_manage_posts', 'instagram_content_publish']);
        $this->client->shouldReceive('getPages')->with('long-token')->andReturn([
            new MetaPageDTO('258426924559094', 'Deejay Innocent', 'page-token', '1784', 'dj_innocent976', ['CREATE_CONTENT']),
            new MetaPageDTO('555', 'Read only page', 'other-token', null, null, ['ANALYZE']),
        ]);
    }
}

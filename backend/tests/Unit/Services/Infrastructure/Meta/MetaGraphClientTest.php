<?php

namespace Tests\Unit\Services\Infrastructure\Meta;

use HiEvents\Exceptions\MetaAppNotConfiguredException;
use HiEvents\Exceptions\MetaGraphApiException;
use HiEvents\Services\Infrastructure\Meta\MetaGraphClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaGraphClientTest extends TestCase
{
    private MetaGraphClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta.app_id' => 'app-id',
            'services.meta.app_secret' => 'app-secret',
            'services.meta.login_config_id' => null,
            'services.meta.graph_api_version' => 'v24.0',
        ]);

        $this->client = new MetaGraphClient;
    }

    public function test_login_url_lists_the_scopes_without_login_configuration(): void
    {
        $url = $this->client->buildLoginUrl('https://api.example.test/public/social/meta/callback', 'state-value');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://www.facebook.com/v24.0/dialog/oauth?', $url);
        $this->assertSame('app-id', $query['client_id']);
        $this->assertSame('state-value', $query['state']);
        $this->assertStringContainsString('instagram_content_publish', $query['scope']);
        $this->assertArrayNotHasKey('config_id', $query);
        $this->assertStringNotContainsString('app-secret', $url);
    }

    public function test_login_url_uses_the_login_configuration_when_set(): void
    {
        config(['services.meta.login_config_id' => 'config-42']);

        parse_str((string) parse_url($this->client->buildLoginUrl('https://cb.test', 's'), PHP_URL_QUERY), $query);

        $this->assertSame('config-42', $query['config_id']);
        $this->assertArrayNotHasKey('scope', $query);
    }

    public function test_login_url_requires_the_app_to_be_configured(): void
    {
        config(['services.meta.app_secret' => null]);

        $this->expectException(MetaAppNotConfiguredException::class);

        $this->client->buildLoginUrl('https://cb.test', 's');
    }

    public function test_code_is_exchanged_then_extended_into_a_long_lived_token(): void
    {
        Http::fake([
            'graph.facebook.com/v24.0/oauth/access_token*' => Http::sequence()
                ->push(['access_token' => 'short-token', 'expires_in' => 3600])
                ->push(['access_token' => 'long-token', 'expires_in' => 5184000]),
        ]);

        $short = $this->client->exchangeCodeForToken('the-code', 'https://cb.test');
        $long = $this->client->exchangeForLongLivedToken($short->accessToken);

        $this->assertSame('long-token', $long->accessToken);
        $this->assertSame(5184000, $long->expiresIn);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'grant_type=fb_exchange_token')
            && str_contains($request->url(), 'fb_exchange_token=short-token'));
    }

    public function test_pages_keep_instagram_account_and_drop_pages_without_token(): void
    {
        Http::fake([
            'graph.facebook.com/v24.0/me/accounts*' => Http::response(['data' => [
                [
                    'id' => '258426924559094',
                    'name' => 'Deejay Innocent',
                    'access_token' => 'page-token',
                    'tasks' => ['CREATE_CONTENT', 'MANAGE'],
                    'instagram_business_account' => ['id' => '1784', 'username' => 'dj_innocent976'],
                ],
                ['id' => '2', 'name' => 'No token page'],
            ]]),
        ]);

        $pages = $this->client->getPages('user-token');

        $this->assertCount(1, $pages);
        $this->assertSame('258426924559094', $pages[0]->id);
        $this->assertSame('1784', $pages[0]->instagramAccountId);
        $this->assertSame('dj_innocent976', $pages[0]->instagramUsername);
        $this->assertTrue($pages[0]->canCreateContent());
    }

    public function test_invalid_token_error_is_recognised(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => [
                'message' => 'Error validating access token: The session has been invalidated.',
                'code' => 190,
                'error_subcode' => 460,
            ]], 400),
        ]);

        try {
            $this->client->getUserId('expired-token');
            $this->fail('An exception was expected.');
        } catch (MetaGraphApiException $exception) {
            $this->assertTrue($exception->isTokenInvalid());
            $this->assertFalse($exception->isRetryable());
            $this->assertSame(190, $exception->getGraphErrorCode());
        }
    }

    public function test_server_errors_and_rate_limits_are_retryable(): void
    {
        Http::fake([
            'graph.facebook.com/v24.0/me/permissions*' => Http::response(['error' => ['message' => 'Too many calls', 'code' => 4]], 400),
            'graph.facebook.com/v24.0/me*' => Http::response(['error' => ['message' => 'Unknown', 'code' => 1, 'is_transient' => true]], 500),
        ]);

        foreach (['getGrantedScopes', 'getUserId'] as $method) {
            try {
                $this->client->{$method}('token');
                $this->fail('An exception was expected.');
            } catch (MetaGraphApiException $exception) {
                $this->assertTrue($exception->isRetryable(), $method);
                $this->assertFalse($exception->isTokenInvalid(), $method);
            }
        }
    }

    public function test_publishing_quota_is_read(): void
    {
        Http::fake([
            'graph.facebook.com/v24.0/1784/content_publishing_limit*' => Http::response(['data' => [
                ['quota_usage' => 12, 'config' => ['quota_total' => 100, 'quota_duration' => 86400]],
            ]]),
        ]);

        $this->assertSame(['usage' => 12, 'total' => 100], $this->client->getInstagramPublishingQuota('1784', 'page-token'));
    }

    public function test_instagram_container_is_created_then_published(): void
    {
        Http::fake([
            'graph.facebook.com/v24.0/1784/media_publish' => Http::response(['id' => 'media-1']),
            'graph.facebook.com/v24.0/1784/media' => Http::response(['id' => 'container-1']),
            'graph.facebook.com/v24.0/container-1*' => Http::response(['status_code' => 'FINISHED', 'id' => 'container-1']),
        ]);

        $containerId = $this->client->createInstagramContainer('1784', 'page-token', ['image_url' => 'https://cdn.test/a.jpg', 'caption' => 'Hello']);

        $this->assertSame('container-1', $containerId);
        $this->assertSame('FINISHED', $this->client->getInstagramContainerStatus($containerId, 'page-token'));
        $this->assertSame('media-1', $this->client->publishInstagramContainer('1784', $containerId, 'page-token'));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://graph.facebook.com/v24.0/1784/media'
            && $request['image_url'] === 'https://cdn.test/a.jpg'
            && $request['access_token'] === 'page-token');
    }

    public function test_page_reel_goes_through_start_upload_and_finish(): void
    {
        Http::fake([
            'graph.facebook.com/v24.0/page-1/video_reels' => Http::sequence()
                ->push(['video_id' => 'video-9', 'upload_url' => 'https://rupload.facebook.com/video-upload/v24.0/video-9'])
                ->push(['success' => true, 'post_id' => 'page-1_post-9']),
            'rupload.facebook.com/*' => Http::response(['success' => true]),
        ]);

        $postId = $this->client->publishPageVideo('page-1', 'page-token', 'video_reels', 'https://cdn.test/reel.mp4', 'Caption');

        $this->assertSame('page-1_post-9', $postId);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://rupload.facebook.com/video-upload/v24.0/video-9')
            && $request->hasHeader('file_url', 'https://cdn.test/reel.mp4')
            && $request->hasHeader('Authorization', 'OAuth page-token'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://graph.facebook.com/v24.0/page-1/video_reels'
            && ($request['upload_phase'] ?? null) === 'finish'
            && $request['video_state'] === 'PUBLISHED'
            && $request['description'] === 'Caption');
    }

    public function test_carousel_feed_post_attaches_the_unpublished_photos(): void
    {
        Http::fake([
            'graph.facebook.com/v24.0/page-1/feed' => Http::response(['id' => 'page-1_post-3']),
        ]);

        $this->assertSame('page-1_post-3', $this->client->publishPageFeedPost('page-1', 'page-token', 'Two photos', null, ['p1', 'p2']));

        Http::assertSent(fn (Request $request) => str_contains(urldecode($request->body()), 'attached_media[0]={"media_fbid":"p1"}')
            && str_contains(urldecode($request->body()), 'attached_media[1]={"media_fbid":"p2"}')
            && ! str_contains($request->body(), 'link='));
    }
}

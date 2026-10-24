<?php

namespace Tests\Unit\Services\Application\Handlers\Social;

use HiEvents\Exceptions\MetaOAuthException;
use HiEvents\Services\Application\Handlers\Social\HandleMetaOAuthCallbackHandler;
use HiEvents\Services\Domain\Social\DTO\MetaOAuthCallbackResultDTO;
use HiEvents\Services\Domain\Social\MetaConnectionService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class HandleMetaOAuthCallbackHandlerTest extends TestCase
{
    private MetaConnectionService|MockInterface $connectionService;

    private HandleMetaOAuthCallbackHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => 'https://app.example.test']);

        $this->connectionService = Mockery::mock(MetaConnectionService::class);
        $this->connectionService->shouldReceive('getSettingsUrl')
            ->andReturnUsing(fn (int $organizerId, array $query) => "https://app.example.test/manage/organizer/$organizerId/settings?".http_build_query($query).'#social');

        $this->handler = new HandleMetaOAuthCallbackHandler($this->connectionService);
    }

    public function test_successful_connection_returns_to_the_page_choice(): void
    {
        $this->connectionService->shouldReceive('readState')->with('state')->andReturn(['organizer_id' => 4, 'account_id' => 3, 'user_id' => 7]);
        $this->connectionService->shouldReceive('handleCallback')->with('code', 'state')->andReturn(new MetaOAuthCallbackResultDTO(4, 'selection-key'));

        $this->assertSame(
            'https://app.example.test/manage/organizer/4/settings?social_selection=selection-key#social',
            $this->handler->handle('code', 'state', null),
        );
    }

    public function test_refusal_on_facebook_returns_cancelled(): void
    {
        $this->connectionService->shouldReceive('readState')->andReturn(['organizer_id' => 4]);
        $this->connectionService->shouldNotReceive('handleCallback');

        $this->assertStringContainsString('social_error=cancelled', $this->handler->handle(null, 'state', 'access_denied'));
    }

    public function test_meta_failure_returns_failed_without_details(): void
    {
        $this->connectionService->shouldReceive('readState')->andReturn(['organizer_id' => 4]);
        $this->connectionService->shouldReceive('handleCallback')->andThrow(new MetaOAuthException('access_token=secret'));

        $url = $this->handler->handle('code', 'state', null);

        $this->assertStringContainsString('social_error=failed', $url);
        $this->assertStringNotContainsString('secret', $url);
    }

    public function test_invalid_state_returns_to_the_organizer_area(): void
    {
        $this->connectionService->shouldReceive('readState')->andThrow(new MetaOAuthException('expired'));

        $this->assertSame('https://app.example.test/manage/organizer?social_error=expired', $this->handler->handle('code', 'forged', null));
    }
}

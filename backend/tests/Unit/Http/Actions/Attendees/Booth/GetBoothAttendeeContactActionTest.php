<?php

namespace Tests\Unit\Http\Actions\Attendees\Booth;

use HiEvents\Services\Application\Handlers\Attendee\Booth\DTO\BoothAttendeeContactDTO;
use HiEvents\Services\Application\Handlers\Attendee\Booth\GetBoothAttendeeContactHandler;
use Mockery as m;
use Tests\TestCase;

class GetBoothAttendeeContactActionTest extends TestCase
{
    private const URL = '/booth/attendees/7/A-7XK2P9Q';

    private const KEY = 'booth-test-key';

    private GetBoothAttendeeContactHandler&m\MockInterface $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = m::mock(GetBoothAttendeeContactHandler::class);
        $this->app->instance(GetBoothAttendeeContactHandler::class, $this->handler);
        config(['app.booth_api_key' => self::KEY]);
    }

    public function test_returns_unauthorized_without_key(): void
    {
        $this->handler->shouldNotReceive('handle');

        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_returns_unauthorized_with_wrong_key(): void
    {
        $this->handler->shouldNotReceive('handle');

        $this->getJson(self::URL, ['X-Booth-Key' => 'wrong'])->assertUnauthorized();
    }

    public function test_returns_unauthorized_when_key_is_not_configured(): void
    {
        config(['app.booth_api_key' => null]);
        $this->handler->shouldNotReceive('handle');

        $this->getJson(self::URL, ['X-Booth-Key' => ''])->assertUnauthorized();
        $this->getJson(self::URL, ['X-Booth-Key' => self::KEY])->assertUnauthorized();
    }

    public function test_returns_not_found_when_handler_finds_nothing(): void
    {
        $this->handler->shouldReceive('handle')->once()->with(7, 'A-7XK2P9Q')->andReturnNull();

        $this->getJson(self::URL, ['X-Booth-Key' => self::KEY])->assertNotFound();
    }

    public function test_returns_only_status_phone_and_email(): void
    {
        $this->handler->shouldReceive('handle')->once()->with(7, 'A-7XK2P9Q')->andReturn(
            new BoothAttendeeContactDTO(status: 'ACTIVE', phone: '+262639000000', email: 'invite@example.com'),
        );

        $this->getJson(self::URL, ['X-Booth-Key' => self::KEY])
            ->assertOk()
            ->assertExactJson([
                'status' => 'ACTIVE',
                'phone' => '+262639000000',
                'email' => 'invite@example.com',
            ]);
    }

    public function test_rejects_non_numeric_event_id(): void
    {
        $this->handler->shouldNotReceive('handle');

        $this->getJson('/booth/attendees/abc/A-7XK2P9Q', ['X-Booth-Key' => self::KEY])->assertNotFound();
    }

    public function test_is_rate_limited(): void
    {
        $this->handler->shouldReceive('handle')->andReturnNull();

        for ($i = 0; $i < 60; $i++) {
            $this->getJson(self::URL, ['X-Booth-Key' => self::KEY])->assertNotFound();
        }

        $this->getJson(self::URL, ['X-Booth-Key' => self::KEY])->assertTooManyRequests();
    }
}

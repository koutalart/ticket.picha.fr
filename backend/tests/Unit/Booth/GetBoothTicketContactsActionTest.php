<?php

namespace Tests\Unit\Booth;

use HiEvents\Services\Application\Handlers\Booth\DTO\BoothTicketContactsDTO;
use HiEvents\Services\Application\Handlers\Booth\GetBoothTicketContactsHandler;
use Mockery as m;
use Tests\TestCase;

class GetBoothTicketContactsActionTest extends TestCase
{
    private const URL = '/internal/booth/events/7/tickets/A-7XK2P9Q/contacts';

    private const KEY = 'booth-test-key';

    private GetBoothTicketContactsHandler&m\MockInterface $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = m::mock(GetBoothTicketContactsHandler::class);
        $this->app->instance(GetBoothTicketContactsHandler::class, $this->handler);
        config(['app.picha_booth_api_key' => self::KEY]);
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

    public function test_returns_service_unavailable_when_key_is_not_configured(): void
    {
        config(['app.picha_booth_api_key' => null]);
        $this->handler->shouldNotReceive('handle');

        $this->getJson(self::URL, ['X-Booth-Key' => ''])->assertServiceUnavailable();
    }

    public function test_returns_not_found_when_ticket_is_not_active_or_unknown(): void
    {
        $this->handler->shouldReceive('handle')->once()->with(7, 'A-7XK2P9Q')->andReturnNull();

        $this->getJson(self::URL, ['X-Booth-Key' => self::KEY])->assertNotFound();
    }

    public function test_returns_only_phone_and_email(): void
    {
        $this->handler->shouldReceive('handle')->once()->with(7, 'A-7XK2P9Q')->andReturn(
            new BoothTicketContactsDTO(phone: '+262639000000', email: 'invite@example.com'),
        );

        $this->getJson(self::URL, ['X-Booth-Key' => self::KEY])
            ->assertOk()
            ->assertExactJson([
                'phone' => '+262639000000',
                'email' => 'invite@example.com',
            ]);
    }

    public function test_rejects_non_numeric_event_id(): void
    {
        $this->handler->shouldNotReceive('handle');

        $this->getJson('/internal/booth/events/abc/tickets/A-7XK2P9Q/contacts', ['X-Booth-Key' => self::KEY])
            ->assertNotFound();
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

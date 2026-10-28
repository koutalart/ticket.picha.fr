<?php

namespace Tests\Unit\Services\Application\Handlers\Event;

use Carbon\Carbon;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Repository\DTO\RecentTicketPurchaseDTO;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\GetRecentEventPurchasesHandler;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class GetRecentEventPurchasesHandlerTest extends TestCase
{
    private EventRepositoryInterface $eventRepository;

    private OrderRepositoryInterface $orderRepository;

    private GetRecentEventPurchasesHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(GetRecentEventPurchasesHandler::cacheKey(42));
        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->handler = new GetRecentEventPurchasesHandler($this->eventRepository, $this->orderRepository);
    }

    protected function tearDown(): void
    {
        Cache::forget(GetRecentEventPurchasesHandler::cacheKey(42));
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_only_quantity_and_minutes_ago_of_recent_purchases(): void
    {
        Carbon::setTestNow(Carbon::now());

        $this->eventRepository->shouldReceive('findFirstWhere')->once()->andReturn(new EventDomainObject);
        $this->orderRepository->shouldReceive('findRecentOnlineTicketPurchases')
            ->once()
            ->withArgs(fn (int $eventId, $since, int $limit) => $eventId === 42
                && $limit === GetRecentEventPurchasesHandler::LIMIT
                && $since->equalTo(Carbon::now()->subHours(GetRecentEventPurchasesHandler::WINDOW_HOURS)))
            ->andReturn(collect([
                new RecentTicketPurchaseDTO(quantity: 2, purchasedAt: Carbon::now()->subMinutes(6)->toDateTimeString()),
                new RecentTicketPurchaseDTO(quantity: 1, purchasedAt: Carbon::now()->subSeconds(20)->toDateTimeString()),
            ]));

        $result = $this->handler->handle(42)->toArray();

        $this->assertSame([
            'purchases' => [
                ['quantity' => 2, 'minutes_ago' => 6],
                ['quantity' => 1, 'minutes_ago' => 0],
            ],
        ], $result);
    }

    public function test_result_is_cached_and_minutes_keep_counting(): void
    {
        Carbon::setTestNow(Carbon::now());

        $this->eventRepository->shouldReceive('findFirstWhere')->once()->andReturn(new EventDomainObject);
        $this->orderRepository->shouldReceive('findRecentOnlineTicketPurchases')->once()->andReturn(collect([
            new RecentTicketPurchaseDTO(quantity: 3, purchasedAt: Carbon::now()->subMinutes(2)->toDateTimeString()),
        ]));

        $this->handler->handle(42);
        Carbon::setTestNow(Carbon::now()->addSeconds(50));
        $second = $this->handler->handle(42)->toArray();

        $this->assertSame(2, $second['purchases'][0]['minutes_ago']);
    }

    public function test_event_that_is_not_live_has_no_purchases(): void
    {
        $this->eventRepository->shouldReceive('findFirstWhere')->once()->andReturnNull();
        $this->orderRepository->shouldNotReceive('findRecentOnlineTicketPurchases');

        $this->assertSame(['purchases' => []], $this->handler->handle(42)->toArray());
    }
}

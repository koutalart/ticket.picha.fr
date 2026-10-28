<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Event;

use Carbon\Carbon;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Repository\DTO\RecentTicketPurchaseDTO;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\DTO\RecentPurchaseDTO;
use HiEvents\Services\Application\Handlers\Event\DTO\RecentPurchasesDTO;
use Illuminate\Support\Facades\Cache;

/**
 * Anonymous social proof for the public event page: only the ticket count and the age of the latest real online orders.
 */
class GetRecentEventPurchasesHandler
{
    public const CACHE_TTL_SECONDS = 60;

    public const WINDOW_HOURS = 48;

    public const LIMIT = 10;

    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OrderRepositoryInterface $orderRepository,
    ) {}

    public function handle(int $eventId): RecentPurchasesDTO
    {
        $purchases = Cache::remember(
            self::cacheKey($eventId),
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->fetch($eventId),
        );

        $now = Carbon::now()->getTimestamp();

        return new RecentPurchasesDTO(purchases: array_map(
            static fn (array $purchase) => new RecentPurchaseDTO(
                quantity: $purchase['quantity'],
                minutes_ago: max(0, intdiv($now - $purchase['purchased_at'], 60)),
            ),
            $purchases,
        ));
    }

    public static function cacheKey(int $eventId): string
    {
        return 'event:'.$eventId.':recent-purchases';
    }

    /**
     * @return array<int, array{quantity: int, purchased_at: int}>
     */
    private function fetch(int $eventId): array
    {
        $event = $this->eventRepository->findFirstWhere([
            'id' => $eventId,
            'status' => EventStatus::LIVE->name,
        ]);

        if ($event === null) {
            return [];
        }

        return $this->orderRepository
            ->findRecentOnlineTicketPurchases($eventId, Carbon::now()->subHours(self::WINDOW_HOURS), self::LIMIT)
            ->map(static fn (RecentTicketPurchaseDTO $purchase) => [
                'quantity' => $purchase->quantity,
                'purchased_at' => Carbon::parse($purchase->purchasedAt)->getTimestamp(),
            ])
            ->values()
            ->all();
    }
}

<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice;

use HiEvents\Helper\KioskSentinelEmail;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\BoxOfficeOrderFilterDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\BoxOfficeOrderListItemDTO;
use HiEvents\Services\Application\Handlers\BoxOffice\DTO\BoxOfficeOrdersPageDTO;

/**
 * Kiosk "Commandes": every order of the event, online and box office, so an
 * operator can find any buyer, reprint tickets and record entries.
 */
class GetBoxOfficeOrdersHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
    ) {}

    public function handle(int $eventId, BoxOfficeOrderFilterDTO $filter): BoxOfficeOrdersPageDTO
    {
        $page = $this->orderRepository->paginateForBoxOffice($eventId, $filter);

        return new BoxOfficeOrdersPageDTO(
            items: array_map(static fn (object $row) => self::toListItem($row), $page->items()),
            total: $page->total(),
            current_page: $page->currentPage(),
            last_page: $page->lastPage(),
            per_page: $page->perPage(),
        );
    }

    public static function toListItem(object $row): BoxOfficeOrderListItemDTO
    {
        $createdAt = $row->created_at;

        return new BoxOfficeOrderListItemDTO(
            public_id: $row->public_id,
            created_at: is_object($createdAt) && method_exists($createdAt, 'toIso8601String')
                ? $createdAt->toIso8601String()
                : ($createdAt !== null ? (string) $createdAt : null),
            first_name: $row->first_name,
            last_name: $row->last_name,
            email: KioskSentinelEmail::isKioskSentinelEmail((string) $row->email) ? null : $row->email,
            phone: $row->phone,
            channel: match (true) {
                $row->box_office_sale_id !== null => BoxOfficeOrderFilterDTO::CHANNEL_BOX_OFFICE,
                (bool) $row->is_manually_created => BoxOfficeOrderFilterDTO::CHANNEL_MANUAL,
                default => BoxOfficeOrderFilterDTO::CHANNEL_ONLINE,
            },
            agent_name: trim(($row->agent_first_name ?? '').' '.($row->agent_last_name ?? '')) ?: null,
            payment_method: $row->payment_method,
            total_gross: (float) $row->total_gross,
            currency: (string) $row->currency,
            status: (string) $row->status,
            payment_status: $row->payment_status,
            ticket_count: (int) $row->ticket_count,
            checked_in_count: (int) $row->checked_in_count,
        );
    }
}

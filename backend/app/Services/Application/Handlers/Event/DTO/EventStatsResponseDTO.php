<?php

namespace HiEvents\Services\Application\Handlers\Event\DTO;

use HiEvents\DataTransferObjects\Attributes\CollectionOf;
use HiEvents\DataTransferObjects\BaseDTO;
use HiEvents\Services\Domain\Event\DTO\EventCheckInStatsResponseDTO;
use HiEvents\Services\Domain\Event\DTO\EventDailyStatsResponseDTO;
use Illuminate\Support\Collection;

class EventStatsResponseDTO extends BaseDTO
{
    public function __construct(
        #[CollectionOf(EventDailyStatsResponseDTO::class)]
        public readonly Collection          $daily_stats,
        public readonly string              $start_date,
        public readonly string              $end_date,

        public int                          $total_products_sold,
        public int                          $total_attendees_registered,

        public int                          $total_orders,
        public float                        $total_gross_sales,
        public float                        $total_fees,
        public float                        $total_tax,
        public float                        $total_views,
        public float                        $total_refunded,
        public float                        $total_awaiting_payment = 0,
        public int                          $orders_awaiting_payment = 0,
        public ?EventCheckInStatsResponseDTO $check_in_stats = null,
        public int                          $total_attendance_confirmed = 0,
        public int                          $total_cancelled_by_attendee = 0,
    )
    {
    }
}

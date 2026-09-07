<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class BoxOfficeStatsDTO extends BaseDataObject
{
    /**
     * @param  array<int, array{payment_method: ?string, sales_count: int, total_amount: float, total_collected: float}>  $by_payment_method
     * @param  array<int, array{agent_user_id: int, agent_name: string, sales_count: int, total_amount: float, total_collected: float}>  $by_agent
     */
    public function __construct(
        public readonly int $sales_count,
        public readonly int $ticket_count,
        public readonly float $total_amount,
        public readonly float $total_collected,
        public readonly array $by_payment_method,
        public readonly array $by_agent,
    ) {}
}

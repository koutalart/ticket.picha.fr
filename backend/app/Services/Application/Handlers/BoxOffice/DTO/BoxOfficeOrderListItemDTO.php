<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class BoxOfficeOrderListItemDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $public_id,
        public readonly ?string $created_at,
        public readonly ?string $first_name,
        public readonly ?string $last_name,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly string $channel,
        public readonly ?string $agent_name,
        public readonly ?string $payment_method,
        public readonly float $total_gross,
        public readonly string $currency,
        public readonly string $status,
        public readonly ?string $payment_status,
        public readonly int $ticket_count,
        public readonly int $checked_in_count,
    ) {}
}

<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\BoxOffice\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class BoxOfficeSaleListItemDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $id,
        public readonly string $created_at,
        public readonly ?string $payment_method,
        public readonly float $amount,
        public readonly float $amount_collected,
        public readonly ?string $phone,
        public readonly int $ticket_count,
        public readonly int $agent_user_id,
        public readonly string $agent_name,
        public readonly string $attendee_name,
        public readonly ?string $attendee_public_id,
        public readonly ?string $order_public_id,
        /** @var list<string> */
        public readonly array $attendee_public_ids,
        public readonly int $checked_in_count,
    ) {}
}

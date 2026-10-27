<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Order\RefundProtection\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

/**
 * How many protections an order needs: the mandatory part (tickets above the threshold, or a protection
 * the organizer made required) and the optional part offered by the checkbox for the other tickets.
 */
class RefundProtectionPlanDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $ticket_quantity,
        public readonly int $mandatory_quantity,
        public readonly int $optional_quantity,
        public readonly bool $is_required_by_organizer,
        public readonly ?float $mandatory_above,
    ) {}

    public function isEmpty(): bool
    {
        return $this->mandatory_quantity <= 0 && $this->optional_quantity <= 0;
    }
}

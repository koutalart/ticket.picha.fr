<?php

declare(strict_types=1);

namespace HiEvents\Resources\LiveChat;

use HiEvents\Resources\BaseResource;
use HiEvents\Services\Application\Handlers\LiveChat\Approval\DTO\RefundApprovalViewDTO;

/**
 * @mixin RefundApprovalViewDTO
 */
class RefundApprovalResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'decision' => $this->decision,
            'organizer_id' => $this->organizerId,
            'organizer_name' => $this->organizerName,
            'event_title' => $this->eventTitle,
            'customer_name' => $this->customerName,
            'order_reference' => $this->orderReference,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'amount_text' => $this->amountText,
            'requested_at' => $this->requestedAt,
            'decided_at' => $this->decidedAt,
            'decided_by_channel' => $this->decidedByChannel,
            'decided_by_name' => $this->decidedByName,
            'can_decide' => $this->canDecide,
        ];
    }
}

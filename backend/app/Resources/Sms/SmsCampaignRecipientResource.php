<?php

declare(strict_types=1);

namespace HiEvents\Resources\Sms;

use HiEvents\DomainObjects\SmsCampaignRecipientDomainObject;
use HiEvents\Resources\BaseResource;

/**
 * @mixin SmsCampaignRecipientDomainObject
 */
class SmsCampaignRecipientResource extends BaseResource
{
    public function toArray($request): array
    {
        $qrLink = $this->getQrLink();

        return [
            'id' => $this->getId(),
            'phone' => $this->getPhone(),
            'contact_name' => $this->getContactName(),
            'status' => $this->getStatus(),
            'failure_reason' => $this->getFailureReason(),
            'scheduled_at' => $this->getScheduledAt(),
            'sent_at' => $this->getSentAt(),
            'delivered_at' => $this->getDeliveredAt(),
            'short_id' => $qrLink?->getShortId(),
            'visits_count' => $qrLink?->getScansCount() ?? 0,
            'has_clicked' => ($qrLink?->getUniqueScansCount() ?? 0) > 0,
            'orders_count' => $qrLink?->getOrdersCount() ?? 0,
            'revenue_gross' => $qrLink?->getRevenueGross() ?? 0.0,
        ];
    }
}

<?php

declare(strict_types=1);

namespace HiEvents\Resources\Email;

use HiEvents\Resources\BaseResource;
use HiEvents\Services\Application\Handlers\Email\DTO\EmailThreadDetailDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsCustomerOrderDTO;

/**
 * @mixin EmailThreadDetailDTO
 */
class EmailThreadDetailResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'thread' => (new EmailThreadResource($this->thread))->toArray($request),
            'messages' => EmailMessageResource::collection($this->messages)->toArray($request),
            'unsubscribed' => $this->unsubscribed,
            'customer_orders' => $this->customerOrders
                ->map(fn (SmsCustomerOrderDTO $order) => [
                    'order_id' => $order->orderId,
                    'public_id' => $order->publicId,
                    'event_id' => $order->eventId,
                    'event_title' => $order->eventTitle,
                    'event_start_date' => $order->eventStartDate,
                    'first_name' => $order->firstName,
                    'last_name' => $order->lastName,
                    'email' => $order->email,
                    'total_gross' => $order->totalGross,
                    'currency' => $order->currency,
                    'status' => $order->status,
                    'created_at' => $order->createdAt,
                ])
                ->values()
                ->all(),
        ];
    }
}

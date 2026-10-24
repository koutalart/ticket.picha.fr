<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

/**
 * Why an order cannot be refunded from the web chat. Only shown to the organizer: the visitor always gets the same
 * neutral answer, which never tells whether the order exists.
 */
enum LiveChatRefundRefusal: string
{
    use BaseEnum;

    case ORDER_NOT_FOUND = 'ORDER_NOT_FOUND';
    case NOT_COMPLETED = 'NOT_COMPLETED';
    case NOT_PAID_WITH_STRIPE = 'NOT_PAID_WITH_STRIPE';
    case ALREADY_REFUNDED = 'ALREADY_REFUNDED';
    case NO_PROTECTION = 'NO_PROTECTION';
    case EVENT_STARTED = 'EVENT_STARTED';
    case DEADLINE_PASSED = 'DEADLINE_PASSED';
    case NO_REFUNDABLE_TICKET = 'NO_REFUNDABLE_TICKET';

    public function label(): string
    {
        return match ($this) {
            self::ORDER_NOT_FOUND => __('order not found or e-mail not matching'),
            self::NOT_COMPLETED => __('order not completed'),
            self::NOT_PAID_WITH_STRIPE => __('order not paid by card online'),
            self::ALREADY_REFUNDED => __('order already refunded or refund pending'),
            self::NO_PROTECTION => __('order without cancellation protection'),
            self::EVENT_STARTED => __('event already started'),
            self::DEADLINE_PASSED => __('refund deadline passed'),
            self::NO_REFUNDABLE_TICKET => __('no ticket left to refund'),
        };
    }
}

<?php

namespace HiEvents\DomainObjects\Enums;

/**
 * PICHA: the only reason that refunds more than the ticket price. The buyer gets back everything they paid,
 * the organizer pays the service fees and the cancellation protection (the application fee is never refunded).
 */
enum RefundReason
{
    use BaseEnum;

    case EVENT_CANCELLED_BY_ORGANIZER;
}

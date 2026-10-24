<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\Refund\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\LiveChatRefundRequestDomainObject;
use Illuminate\Support\Collection;

/**
 * A refund checked again on the server and locked (status PROCESSING), ready to be sent to Stripe.
 */
class LiveChatPreparedRefundDTO extends BaseDataObject
{
    /**
     * @param  Collection<int, LiveChatRefundTicketDTO>  $tickets
     */
    public function __construct(
        public readonly LiveChatRefundRequestDomainObject $request,
        public readonly LiveChatRefundEligibilityDTO $eligibility,
        public readonly Collection $tickets,
        public readonly float $amount,
    ) {}
}

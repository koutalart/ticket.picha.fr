<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\Refund\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\LiveChatMessageDomainObject;
use HiEvents\DomainObjects\LiveChatSessionDomainObject;
use Illuminate\Support\Collection;

/**
 * What the refund card of the chat shows next: the code field, the tickets to choose, the confirmation,
 * or nothing (the request goes to a person).
 */
class LiveChatRefundStepDTO extends BaseDataObject
{
    public const CODE = 'code';

    public const TICKETS = 'tickets';

    public const REFUNDED = 'refunded';

    public const PENDING = 'pending';

    public const UNAVAILABLE = 'unavailable';

    /**
     * @param  Collection<int, LiveChatMessageDomainObject>  $messages
     * @param  Collection<int, LiveChatRefundTicketDTO>  $tickets
     */
    public function __construct(
        public readonly LiveChatSessionDomainObject $session,
        public readonly string $step,
        public readonly Collection $messages,
        public readonly Collection $tickets = new Collection,
        public readonly int $maxTickets = 0,
        public readonly ?string $currency = null,
        public readonly ?float $amount = null,
        public readonly ?string $error = null,
        public readonly ?int $attemptsLeft = null,
        public readonly bool $askContact = false,
    ) {}
}

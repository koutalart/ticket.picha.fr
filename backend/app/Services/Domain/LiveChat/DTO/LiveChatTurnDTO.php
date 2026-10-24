<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\LiveChatMessageDomainObject;
use HiEvents\DomainObjects\LiveChatSessionDomainObject;
use Illuminate\Support\Collection;

/**
 * What the chat window shows after an exchange: the new messages, whether to show the small phone / e-mail form,
 * whether to switch to the classic contact form (daily budget of the assistant used up), and whether to show the refund
 * card (order number and order e-mail) after a refund request.
 */
class LiveChatTurnDTO extends BaseDataObject
{
    /**
     * @param  Collection<int, LiveChatMessageDomainObject>  $messages
     */
    public function __construct(
        public readonly LiveChatSessionDomainObject $session,
        public readonly Collection $messages,
        public readonly bool $askContact = false,
        public readonly bool $contactFormFallback = false,
        public readonly ?string $greeting = null,
        public readonly bool $refundForm = false,
    ) {}
}

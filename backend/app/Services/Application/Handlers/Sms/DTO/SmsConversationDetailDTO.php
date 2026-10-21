<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Services\Domain\Sms\DTO\SmsCustomerOrderDTO;
use Illuminate\Support\Collection;

class SmsConversationDetailDTO extends BaseDataObject
{
    /**
     * @param  Collection<int, SmsMessageDomainObject>  $messages
     * @param  Collection<int, SmsCustomerOrderDTO>  $customerOrders
     */
    public function __construct(
        public readonly SmsConversationDomainObject $conversation,
        public readonly Collection $messages,
        public readonly Collection $customerOrders,
        public readonly bool $optedOut,
    ) {}
}

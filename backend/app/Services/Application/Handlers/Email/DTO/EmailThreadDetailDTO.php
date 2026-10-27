<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Email\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\EmailMessageDomainObject;
use HiEvents\DomainObjects\EmailThreadDomainObject;
use HiEvents\Services\Domain\Sms\DTO\SmsCustomerOrderDTO;
use Illuminate\Support\Collection;

class EmailThreadDetailDTO extends BaseDataObject
{
    /**
     * @param  Collection<int, EmailMessageDomainObject>  $messages
     * @param  Collection<int, SmsCustomerOrderDTO>  $customerOrders
     */
    public function __construct(
        public readonly EmailThreadDomainObject $thread,
        public readonly Collection $messages,
        public readonly Collection $customerOrders,
        public readonly bool $unsubscribed,
    ) {}
}

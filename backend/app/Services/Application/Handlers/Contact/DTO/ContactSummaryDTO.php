<?php

namespace HiEvents\Services\Application\Handlers\Contact\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class ContactSummaryDTO extends BaseDataObject
{
    public function __construct(
        public readonly int   $total,
        public readonly int   $email_consent,
        public readonly int   $sms_consent,
        public readonly int   $repeat_customers,
        public readonly float $total_spent,
    )
    {
    }
}

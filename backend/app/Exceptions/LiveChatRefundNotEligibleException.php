<?php

declare(strict_types=1);

namespace HiEvents\Exceptions;

use Exception;
use HiEvents\DomainObjects\Enums\LiveChatRefundRefusal;

class LiveChatRefundNotEligibleException extends Exception
{
    public function __construct(public readonly LiveChatRefundRefusal $refusal)
    {
        parent::__construct($refusal->label());
    }
}

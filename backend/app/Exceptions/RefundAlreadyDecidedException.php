<?php

declare(strict_types=1);

namespace HiEvents\Exceptions;

use Exception;
use HiEvents\DomainObjects\LiveChatRefundRequestDomainObject;

class RefundAlreadyDecidedException extends Exception
{
    public function __construct(public readonly LiveChatRefundRequestDomainObject $request, string $message = '')
    {
        parent::__construct($message);
    }
}

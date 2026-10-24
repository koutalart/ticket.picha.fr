<?php

declare(strict_types=1);

namespace HiEvents\Exceptions;

use Exception;

class LiveChatInvalidContactException extends Exception
{
    public function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }
}

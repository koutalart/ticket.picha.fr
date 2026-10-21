<?php

declare(strict_types=1);

namespace HiEvents\Exceptions;

use Exception;

class SmsReminderValidationException extends Exception
{
    public function __construct(string $message, private readonly string $field = 'message')
    {
        parent::__construct($message);
    }

    public function getField(): string
    {
        return $this->field;
    }
}

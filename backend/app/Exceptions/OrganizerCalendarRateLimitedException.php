<?php

namespace HiEvents\Exceptions;

use Exception;

class OrganizerCalendarRateLimitedException extends Exception
{
    public function __construct(string $message, public readonly int $retryAfterSeconds)
    {
        parent::__construct($message);
    }
}

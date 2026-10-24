<?php

declare(strict_types=1);

namespace HiEvents\Exceptions;

use Exception;

class LiveChatLimitReachedException extends Exception
{
    public const SESSION = 'session';

    public const IP = 'ip';

    public const NEW_SESSIONS = 'new_sessions';

    public function __construct(string $message, public readonly string $limit)
    {
        parent::__construct($message);
    }
}

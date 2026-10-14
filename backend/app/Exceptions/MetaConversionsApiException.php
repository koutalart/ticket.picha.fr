<?php

declare(strict_types=1);

namespace HiEvents\Exceptions;

use Exception;
use Throwable;

class MetaConversionsApiException extends Exception
{
    public function __construct(
        string $message,
        private readonly bool $retryable = false,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}

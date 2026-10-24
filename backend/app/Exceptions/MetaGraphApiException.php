<?php

declare(strict_types=1);

namespace HiEvents\Exceptions;

use Exception;
use Throwable;

class MetaGraphApiException extends Exception
{
    private const INVALID_TOKEN_CODES = [102, 190];

    private const RATE_LIMIT_CODES = [4, 17, 32, 341, 613];

    private const PUBLISHING_LIMIT_SUBCODES = [2207042];

    public function __construct(
        string $message,
        private readonly bool $retryable = false,
        int $code = 0,
        private readonly ?int $graphErrorCode = null,
        private readonly ?int $graphErrorSubcode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function isRetryable(): bool
    {
        return $this->retryable || $this->isRateLimited();
    }

    public function isTokenInvalid(): bool
    {
        return in_array($this->graphErrorCode, self::INVALID_TOKEN_CODES, true);
    }

    public function isRateLimited(): bool
    {
        return in_array($this->graphErrorCode, self::RATE_LIMIT_CODES, true)
            || in_array($this->graphErrorSubcode, self::PUBLISHING_LIMIT_SUBCODES, true);
    }

    public function getGraphErrorCode(): ?int
    {
        return $this->graphErrorCode;
    }
}

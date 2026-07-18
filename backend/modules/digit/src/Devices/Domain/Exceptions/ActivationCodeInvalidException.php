<?php

declare(strict_types=1);

namespace Digit\Devices\Domain\Exceptions;

class ActivationCodeInvalidException extends \RuntimeException
{
    public function __construct(private readonly string $reason)
    {
        parent::__construct("Activation code invalid: {$reason}");
    }

    public function reason(): string
    {
        return $this->reason;
    }
}

<?php

declare(strict_types=1);

namespace HiEvents\Exceptions;

use Exception;

class QrLinkReferenceException extends Exception
{
    public function __construct(
        private readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getField(): string
    {
        return $this->field;
    }
}

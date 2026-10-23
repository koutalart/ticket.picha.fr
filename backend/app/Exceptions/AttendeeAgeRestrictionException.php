<?php

namespace HiEvents\Exceptions;

use Exception;

class AttendeeAgeRestrictionException extends Exception
{
    /**
     * @param array<string, string[]> $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct((string)(array_values($errors)[0][0] ?? ''));
    }

    /**
     * @return array<string, string[]>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}

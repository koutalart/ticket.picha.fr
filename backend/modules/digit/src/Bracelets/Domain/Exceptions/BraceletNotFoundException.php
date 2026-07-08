<?php

declare(strict_types=1);

namespace Digit\Bracelets\Domain\Exceptions;

class BraceletNotFoundException extends BraceletValidationException
{
    public function reason(): string
    {
        return 'bracelet_not_found';
    }
}

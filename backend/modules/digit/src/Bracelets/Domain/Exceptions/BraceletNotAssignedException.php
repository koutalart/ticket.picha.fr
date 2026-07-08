<?php

declare(strict_types=1);

namespace Digit\Bracelets\Domain\Exceptions;

use Digit\Bracelets\Domain\Enums\BraceletStatus;

/**
 * The bracelet is known and belongs to the right event, but its status is
 * not ASSIGNED (e.g. GENERATED, PRINTED, REVOKED, COMPROMISED) so it
 * cannot be used for entry.
 */
class BraceletNotAssignedException extends BraceletValidationException
{
    public function __construct(public readonly BraceletStatus $actualStatus)
    {
        parent::__construct("Bracelet status is {$actualStatus->value}, not ASSIGNED.");
    }

    public function reason(): string
    {
        return 'bracelet_not_assigned:' . $this->actualStatus->value;
    }
}

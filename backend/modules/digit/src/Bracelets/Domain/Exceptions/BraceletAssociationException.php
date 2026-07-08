<?php

declare(strict_types=1);

namespace Digit\Bracelets\Domain\Exceptions;

use Exception;

/**
 * Raised by BraceletAssociationService when an associate/revoke/replace
 * operation violates a Bracelets business rule (bracelet not associable,
 * attendee already has an active bracelet, etc.). Kept separate from
 * BraceletValidationException (scan-time rejections) since these happen
 * at guichet/admin time, with a different caller and different UI needs.
 */
class BraceletAssociationException extends Exception
{
}

<?php

declare(strict_types=1);

namespace Digit\Bracelets\Domain\Exceptions;

/**
 * The bracelet exists and its signature is valid, but it belongs to a
 * different event than the one the scanning device is authenticated for.
 * Defense in depth: the signature is already scoped per-event via the
 * event's own secret, so this should be unreachable in practice - but it
 * is checked explicitly rather than assumed.
 */
class BraceletEventMismatchException extends BraceletValidationException
{
    public function reason(): string
    {
        return 'event_mismatch';
    }
}

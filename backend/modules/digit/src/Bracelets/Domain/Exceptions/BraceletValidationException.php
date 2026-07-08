<?php

declare(strict_types=1);

namespace Digit\Bracelets\Domain\Exceptions;

use Exception;

/**
 * Base for every rejection the Bracelets module can produce. Each subclass
 * carries its own distinct `reason` code so that callers (HTTP actions
 * today, the future PWA scanner tomorrow) can show a precise message
 * without leaking more information than necessary (e.g. never distinguish
 * "wrong signature" from "unknown code" in the response body).
 */
abstract class BraceletValidationException extends Exception
{
    abstract public function reason(): string;
}

<?php

declare(strict_types=1);

namespace Digit\Bracelets\Domain\Exceptions;

/**
 * Payload is malformed (wrong shape), uses an unrecognized version prefix,
 * or fails HMAC signature verification. Deliberately does not distinguish
 * these cases in the reason() string exposed to callers - all three mean
 * "this QR cannot be trusted", and finer detail would only help an
 * attacker calibrate forgery attempts.
 */
class InvalidBraceletPayloadException extends BraceletValidationException
{
    public function reason(): string
    {
        return 'invalid_payload';
    }
}

<?php

namespace HiEvents\Services\Domain\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\Helper\Url;

class AttendeeAttendanceLinkGenerator
{
    private const CODE_LENGTH = 40;

    public function generateCode(AttendeeDomainObject $attendee): string
    {
        return substr(
            hash_hmac('sha256', 'attendee-attendance|' . $attendee->getId(), (string)config('app.key')),
            0,
            self::CODE_LENGTH,
        );
    }

    public function isValidCode(AttendeeDomainObject $attendee, ?string $code): bool
    {
        if ($code === null || $code === '') {
            return false;
        }

        return hash_equals($this->generateCode($attendee), $code);
    }

    public function getAttendanceUrl(AttendeeDomainObject $attendee): string
    {
        $url = sprintf(
            Url::getPublicFrontEndUrlForEventId(Url::ATTENDEE_ATTENDANCE, $attendee->getEventId()),
            $attendee->getEventId(),
            $attendee->getShortId(),
        );

        return $url . '?' . http_build_query(['code' => $this->generateCode($attendee)]);
    }
}

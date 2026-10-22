<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Attendee\AttendanceAccess;

use HiEvents\DomainObjects\Enums\AttendanceAccessChannel;
use HiEvents\Exceptions\InvalidAttendanceAccessContactException;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\DTO\AttendanceAccessContactDTO;

class AttendanceAccessContactParser
{
    /**
     * @throws InvalidAttendanceAccessContactException
     */
    public function parse(string $contact): AttendanceAccessContactDTO
    {
        $trimmed = trim($contact);

        if (str_contains($trimmed, '@')) {
            $email = mb_strtolower($trimmed);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw $this->invalid();
            }

            return new AttendanceAccessContactDTO(AttendanceAccessChannel::EMAIL, $email);
        }

        if (preg_match('/^[0-9+\-\s().]{6,25}$/', $trimmed) !== 1) {
            throw $this->invalid();
        }

        $phone = SmsPhoneNormalizer::normalize($trimmed);
        if ($phone === null) {
            throw $this->invalid();
        }

        return new AttendanceAccessContactDTO(AttendanceAccessChannel::PHONE, $phone);
    }

    private function invalid(): InvalidAttendanceAccessContactException
    {
        return new InvalidAttendanceAccessContactException(
            __('Please enter the email address or phone number used to register.', [], CustomerLocale::get())
        );
    }
}

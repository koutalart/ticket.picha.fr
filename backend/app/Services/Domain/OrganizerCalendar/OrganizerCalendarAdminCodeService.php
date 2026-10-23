<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\OrganizerCalendar;

use HiEvents\DomainObjects\Generated\OrganizerCalendarDomainObjectAbstract as Calendar;
use HiEvents\DomainObjects\OrganizerCalendarDomainObject;
use HiEvents\Exceptions\InvalidOrganizerCalendarCodeException;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Repository\Interfaces\OrganizerCalendarRepositoryInterface;
use Illuminate\Support\Carbon;

/**
 * A long-lived admin code per calendar, shown once when generated and stored only as an HMAC.
 * It opens the calendar like the SMS code, without any SMS. Generating a new one replaces the previous one.
 */
class OrganizerCalendarAdminCodeService
{
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const LENGTH = 20;

    public const MIN_LENGTH = 16;

    public function __construct(
        private readonly OrganizerCalendarRepositoryInterface $calendarRepository,
    ) {}

    /**
     * @return string the code, grouped by 4 for reading (dashes and spaces are ignored when typed)
     */
    public function generate(OrganizerCalendarDomainObject $calendar): string
    {
        $code = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        $this->calendarRepository->updateFromArray($calendar->getId(), [
            Calendar::ADMIN_CODE_HASH => $this->hash($calendar->getId(), $code),
            Calendar::ADMIN_CODE_CREATED_AT => Carbon::now()->toDateTimeString(),
        ]);

        return implode('-', str_split($code, 4));
    }

    public function revoke(OrganizerCalendarDomainObject $calendar): void
    {
        $this->calendarRepository->updateFromArray($calendar->getId(), [
            Calendar::ADMIN_CODE_HASH => null,
            Calendar::ADMIN_CODE_CREATED_AT => null,
        ]);
    }

    /**
     * A submitted value long enough to be an admin code (a SMS code has 6 digits).
     */
    public static function looksLikeAdminCode(string $submitted): bool
    {
        return strlen(self::normalize($submitted)) >= self::MIN_LENGTH;
    }

    /**
     * @throws InvalidOrganizerCalendarCodeException
     */
    public function verify(OrganizerCalendarDomainObject $calendar, string $submitted): void
    {
        $stored = $calendar->getAdminCodeHash();
        $candidate = $this->hash($calendar->getId(), self::normalize($submitted));

        if ($stored === null || ! hash_equals($stored, $candidate)) {
            throw new InvalidOrganizerCalendarCodeException(
                __('This code is incorrect or has expired. Request a new code.', [], CustomerLocale::get())
            );
        }
    }

    public static function normalize(string $submitted): string
    {
        return strtoupper((string) preg_replace('/[\s-]+/', '', $submitted));
    }

    private function hash(int $calendarId, string $code): string
    {
        return hash_hmac('sha256', $calendarId.'|'.$code, 'organizer-calendar-admin-code|'.config('app.key'));
    }
}

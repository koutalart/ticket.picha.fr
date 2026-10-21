<?php

namespace HiEvents\Services\Domain\Attendee;

use Carbon\CarbonImmutable;
use HiEvents\DomainObjects\Enums\AgeRestrictionRule;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\Exceptions\AttendeeAgeRestrictionException;
use Throwable;

class AttendeeAgeRestrictionService
{
    private const OLDEST_ACCEPTED_BIRTH_YEAR = 1900;

    public function isEnabled(EventSettingDomainObject $settings): bool
    {
        return $this->getRule($settings) !== AgeRestrictionRule::NONE;
    }

    public function getReferenceDate(EventDomainObject $event): CarbonImmutable
    {
        $timezone = $event->getTimezone() ?: 'UTC';

        if ($event->getStartDate() === null) {
            return CarbonImmutable::now($timezone)->startOfDay();
        }

        return CarbonImmutable::parse($event->getStartDate(), 'UTC')
            ->setTimezone($timezone)
            ->startOfDay();
    }

    /**
     * @param array<int|string, ?string> $birthDatesByProductIndex birth dates of ticket attendees, keyed by their index in the request
     * @throws AttendeeAgeRestrictionException
     */
    public function validate(
        EventSettingDomainObject $settings,
        CarbonImmutable          $referenceDate,
        array                    $birthDatesByProductIndex,
    ): void
    {
        $rule = $this->getRule($settings);

        if ($rule === AgeRestrictionRule::NONE || $birthDatesByProductIndex === []) {
            return;
        }

        $minimumAge = max(1, $settings->getAgeRestrictionMinimumAge());
        $referenceDay = $referenceDate->format('Y-m-d');

        $errors = [];
        $minorIndexes = [];
        $hasAdult = false;

        foreach ($birthDatesByProductIndex as $index => $rawBirthDate) {
            $key = $this->errorKey($index);

            if ($rawBirthDate === null || trim($rawBirthDate) === '') {
                $errors[$key][] = __('Date of birth is required.');
                continue;
            }

            $birthDate = $this->parseBirthDate($rawBirthDate);

            if ($birthDate === null || $birthDate->format('Y-m-d') > $referenceDay) {
                $errors[$key][] = __('Please enter a valid date of birth.');
                continue;
            }

            if ($this->hasReachedAge($birthDate, $referenceDate, $minimumAge)) {
                $hasAdult = true;
            } else {
                $minorIndexes[] = $index;
            }
        }

        if ($errors !== []) {
            throw new AttendeeAgeRestrictionException($errors);
        }

        if ($minorIndexes === []) {
            return;
        }

        if ($rule === AgeRestrictionRule::MINIMUM_AGE) {
            $message = __('This event is restricted to attendees aged :age or over.', ['age' => $minimumAge]);
            throw new AttendeeAgeRestrictionException($this->errorsFor($minorIndexes, $message));
        }

        if (!$hasAdult) {
            $message = __('Attendees under :age must be accompanied by an adult registered in the same order.', ['age' => $minimumAge]);
            throw new AttendeeAgeRestrictionException($this->errorsFor($minorIndexes, $message));
        }
    }

    public function hasReachedAge(CarbonImmutable $birthDate, CarbonImmutable $referenceDate, int $age): bool
    {
        return $birthDate->addYearsWithOverflow($age)->format('Y-m-d') <= $referenceDate->format('Y-m-d');
    }

    public function normalizeBirthDate(?string $rawBirthDate): ?string
    {
        if ($rawBirthDate === null || trim($rawBirthDate) === '') {
            return null;
        }

        return $this->parseBirthDate($rawBirthDate)?->format('Y-m-d');
    }

    private function getRule(EventSettingDomainObject $settings): AgeRestrictionRule
    {
        return AgeRestrictionRule::tryFrom($settings->getAgeRestrictionRule()) ?? AgeRestrictionRule::NONE;
    }

    private function parseBirthDate(string $rawBirthDate): ?CarbonImmutable
    {
        $rawBirthDate = trim($rawBirthDate);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawBirthDate)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $rawBirthDate, 'UTC');
        } catch (Throwable) {
            return null;
        }

        if (!$date || $date->format('Y-m-d') !== $rawBirthDate || $date->year < self::OLDEST_ACCEPTED_BIRTH_YEAR) {
            return null;
        }

        return $date;
    }

    private function errorKey(int|string $index): string
    {
        return 'products.' . $index . '.birth_date';
    }

    /**
     * @param array<int|string> $indexes
     * @return array<string, string[]>
     */
    private function errorsFor(array $indexes, string $message): array
    {
        $errors = [];

        foreach ($indexes as $index) {
            $errors[$this->errorKey($index)][] = $message;
        }

        return $errors;
    }
}

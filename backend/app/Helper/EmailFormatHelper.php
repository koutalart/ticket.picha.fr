<?php

declare(strict_types=1);

namespace HiEvents\Helper;

use Carbon\Carbon;
use HiEvents\DomainObjects\EventDomainObject;

/**
 * Dates and amounts in e-mails, written the way the recipient's language expects them
 * (« dimanche 11 octobre 2026 à 18h00 », « 45,99 € »).
 */
class EmailFormatHelper
{
    public static function eventDate(EventDomainObject $event): string
    {
        $start = self::start($event);
        if ($start === null) {
            return '';
        }

        return self::date($start);
    }

    public static function eventTime(EventDomainObject $event): string
    {
        $start = self::start($event);
        if ($start === null) {
            return '';
        }

        return self::time($start);
    }

    public static function eventDateTime(EventDomainObject $event): string
    {
        if (self::start($event) === null) {
            return '';
        }

        return __(':date at :time', ['date' => self::eventDate($event), 'time' => self::eventTime($event)]);
    }

    public static function dateTime(string $utcDateTime, ?string $timezone): string
    {
        $date = self::local($utcDateTime, $timezone);

        return __(':date at :time', ['date' => self::date($date), 'time' => self::time($date)]);
    }

    public static function localDate(string $utcDateTime, ?string $timezone): string
    {
        return self::date(self::local($utcDateTime, $timezone));
    }

    public static function localTime(string $utcDateTime, ?string $timezone): string
    {
        return self::time(self::local($utcDateTime, $timezone));
    }

    public static function money(float|int $amount, string $currencyCode): string
    {
        return Currency::format($amount, $currencyCode, str_replace('-', '_', app()->getLocale()));
    }

    private static function start(EventDomainObject $event): ?Carbon
    {
        if (! $event->getStartDate()) {
            return null;
        }

        return self::local($event->getStartDate(), $event->getTimezone());
    }

    private static function local(string $utcDateTime, ?string $timezone): Carbon
    {
        return Carbon::parse($utcDateTime, 'UTC')
            ->setTimezone($timezone ?: 'UTC')
            ->locale(app()->getLocale());
    }

    private static function date(Carbon $date): string
    {
        return self::isFrench()
            ? $date->isoFormat('dddd D MMMM YYYY')
            : $date->isoFormat('dddd, MMMM D, YYYY');
    }

    private static function time(Carbon $date): string
    {
        return self::isFrench() ? $date->format('H\\hi') : $date->format('g:i A');
    }

    private static function isFrench(): bool
    {
        return str_starts_with(app()->getLocale(), 'fr');
    }
}

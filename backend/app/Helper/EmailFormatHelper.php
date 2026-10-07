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

        return self::isFrench()
            ? $start->isoFormat('dddd D MMMM YYYY')
            : $start->isoFormat('dddd, MMMM D, YYYY');
    }

    public static function eventTime(EventDomainObject $event): string
    {
        $start = self::start($event);
        if ($start === null) {
            return '';
        }

        return self::isFrench() ? $start->format('H\\hi') : $start->format('g:i A');
    }

    public static function eventDateTime(EventDomainObject $event): string
    {
        if (self::start($event) === null) {
            return '';
        }

        return __(':date at :time', ['date' => self::eventDate($event), 'time' => self::eventTime($event)]);
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

        return Carbon::parse($event->getStartDate(), 'UTC')
            ->setTimezone($event->getTimezone() ?: 'UTC')
            ->locale(app()->getLocale());
    }

    private static function isFrench(): bool
    {
        return str_starts_with(app()->getLocale(), 'fr');
    }
}

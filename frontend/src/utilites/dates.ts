/**
 * All dates are stored in UTC in the database. The timezone for the account or event should be used to
 * display the date in the correct timezone.
 */

import dayjs from "dayjs";
import relativeTime from "dayjs/plugin/relativeTime";
import utc from 'dayjs/plugin/utc';
import timezone from 'dayjs/plugin/timezone';
import advanced from 'dayjs/plugin/advancedFormat';
import { isSsr } from "./helpers.ts";
import { getClientLocale, SupportedLocales } from "../locales.ts";
import { localeFormats } from "./dateLocales.ts";

dayjs.extend(utc);
dayjs.extend(relativeTime);
dayjs.extend(timezone);
dayjs.extend(advanced)

export const formatDate = (date: string, format: string, tz: string): string => {
    return dayjs.utc(date).tz(tz).format(format);
};

export type DateFormatType = 'fullDateTime' | 'shortDateTime' | 'shortDate' | 'chartDate' | 'monthShort' | 'dayOfMonth' | 'dayName' | 'timeOnly' | 'timezone';

/**
 * Safely get a supported locale, falling back to 'en' if not supported.
 */
const getSafeLocale = (locale?: string): SupportedLocales => {
    if (locale && locale in localeFormats) {
        return locale as SupportedLocales;
    }
    return 'en';
};

/**
 * Format date with locale-specific formatting.
 * Uses locale() method on the dayjs instance to avoid global state mutation.
 */
export const formatDateWithLocale = (
    date: string,
    formatType: DateFormatType,
    tz: string,
    locale?: SupportedLocales | string
): string => {
    const resolvedLocale = locale || getClientLocale();
    const safeLocale = getSafeLocale(resolvedLocale);
    const format = localeFormats[safeLocale][formatType];

    return dayjs.utc(date).tz(tz).locale(safeLocale).format(format);
};

/**
 * Format date with user's preferred locale (from user settings or browser).
 * Priority: user settings > browser locale > English fallback.
 */
export const formatDateForUser = (
    date: string,
    formatType: DateFormatType,
    tz: string,
    userLocaleFromSettings?: string
): string => {
    return formatDateWithLocale(date, formatType, tz, userLocaleFromSettings);
};

/**
 * Format date in a short, readable format with optional timezone.
 * Uses locale-aware formatting.
 */
export const prettyDate = (date: string, tz: string, showTimezoneOffset: boolean = false, locale?: string): string => {
    const formatted = formatDateWithLocale(date, 'shortDateTime', tz, locale);

    if (showTimezoneOffset) {
        const tzAbbr = formatDateWithLocale(date, 'timezone', tz, locale);
        return `${formatted} (${tzAbbr})`;
    }

    return formatted;
};

/**
 * Readable event date range, in the event timezone:
 * "samedi 7 novembre, 21:00 - 05:00" when the event ends the same day or during the following night,
 * "samedi 7 novembre, 21:00 - dimanche 8 novembre, 18:00" over several days.
 * The year is only shown when it is not the current one.
 */
export const formatEventDateRange = (
    startDate: string,
    endDate: string | null | undefined,
    tz: string,
    locale?: SupportedLocales | string
): string => {
    const safeLocale = getSafeLocale(locale || getClientLocale());
    const formats = localeFormats[safeLocale];
    const currentYear = dayjs().tz(tz).year();
    const start = dayjs.utc(startDate).tz(tz).locale(safeLocale);

    const day = (date: dayjs.Dayjs) => date.year() === currentYear
        ? date.format(formats.dayName)
        : `${date.format(formats.dayName)} ${date.format('YYYY')}`;
    const time = (date: dayjs.Dayjs) => date.format(formats.timeOnly);

    if (!endDate) {
        return `${day(start)}, ${time(start)}`;
    }

    const end = dayjs.utc(endDate).tz(tz).locale(safeLocale);
    const sameDay = end.isSame(start, 'day');
    const endsDuringTheNight = end.isSame(start.add(1, 'day'), 'day') && end.hour() < 12;

    if (sameDay || endsDuringTheNight) {
        return `${day(start)}, ${time(start)} - ${time(end)}`;
    }

    return `${day(start)}, ${time(start)} - ${day(end)}, ${time(end)}`;
};

/**
 * We don't explicitly convert to the event timezone here as we want to
 * display the 'ago' time in the user's timezone.
 *
 * @param date string
 */
export const relativeDate = (date: string): string => {
    const dateInUTC = dayjs.utc(date);
    const locale = getClientLocale();

    return dayjs().locale(locale).to(dateInUTC);
};

export const getDateTimePickerFormat = (): string => {
    const locale = getClientLocale();
    const safeLocale = getSafeLocale(locale);
    return localeFormats[safeLocale].dateTimePicker;
};

export const utcToTz = (date: undefined | string | Date, tz: string): string | undefined => {
    if (!date) {
        return undefined;
    }
    // eslint-disable-next-line lingui/no-unlocalized-strings
    return dayjs.utc(date).tz(tz).format('YYYY-MM-DDTHH:mm');
};

export const tzToUtc = (date: string, tz: string): string => {
    return dayjs.tz(date, tz).utc().format();
};

/**
 * Converts a datetime to the user's browser timezone with locale-aware formatting.
 * Falls back to provided timezone for SSR.
 */
export const dateToBrowserTz = (
    date: string,
    fallbackTz: string,
    locale?: string,
    showTimezone: boolean = true
): string => {
    const userTimezone = !isSsr()
        ? Intl.DateTimeFormat().resolvedOptions().timeZone
        : fallbackTz;

    const formatted = formatDateWithLocale(date, 'shortDateTime', userTimezone, locale);

    if (!showTimezone) {
        return formatted;
    }

    const tzAbbr = formatDateWithLocale(date, 'timezone', userTimezone, locale);
    return `${formatted} ${tzAbbr}`;
};

const SQL_DATE_TIME = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?$/;

/**
 * Parses a UTC date coming from the API into a timestamp, without relying on
 * the browser's Date parser (Safari iOS rejects "YYYY-MM-DD HH:mm:ss").
 */
export const parseUtcTimestamp = (date: string | null | undefined): number | null => {
    if (!date || typeof date !== 'string') {
        return null;
    }

    const value = date.trim();
    const sqlMatch = value.match(SQL_DATE_TIME);

    if (sqlMatch) {
        const [, year, month, day, hours, minutes, seconds] = sqlMatch;
        return Date.UTC(
            Number(year),
            Number(month) - 1,
            Number(day),
            Number(hours),
            Number(minutes),
            Number(seconds ?? 0),
        );
    }

    const parsed = dayjs.utc(value);
    return parsed.isValid() ? parsed.valueOf() : null;
};

export const isDateInFuture = (date: string): boolean => {
    const timestamp = parseUtcTimestamp(date);
    return timestamp !== null && timestamp > Date.now();
};

export const isDateInPast = (date: string): boolean => {
    return dayjs.utc(date).diff(dayjs()) < 0;
}

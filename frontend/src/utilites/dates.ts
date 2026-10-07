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

export const isDateInFuture = (date: string): boolean => {
    return dayjs.utc(date).diff(dayjs()) > 0;
};

export const isDateInPast = (date: string): boolean => {
    return dayjs.utc(date).diff(dayjs()) < 0;
}

/**
 * Ticket date as printed on PICHA tickets, e.g. « Sam. 5 sept. 2026 ».
 */
export const formatTicketDate = (date: string, tz: string, locale?: string): string => {
    const formatted = dayjs.utc(date).tz(tz).locale(getSafeLocale(locale || getClientLocale())).format('ddd D MMM YYYY');
    return formatted.charAt(0).toUpperCase() + formatted.slice(1);
};

/**
 * Ticket hours as printed on PICHA tickets, e.g. « 21h00 » or « 16h00 - 02h30 ».
 */
export const formatTicketHours = (start: string, end: string | undefined | null, tz: string): string => {
    // eslint-disable-next-line lingui/no-unlocalized-strings
    const format = 'HH[h]mm';
    const from = dayjs.utc(start).tz(tz).format(format);
    return end ? `${from} - ${dayjs.utc(end).tz(tz).format(format)}` : from;
};


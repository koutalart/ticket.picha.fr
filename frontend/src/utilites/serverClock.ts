const SQL_DATE_TIME = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?$/;
const HAS_ZONE = /(Z|[+-]\d{2}:?\d{2})$/i;

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

    const parsed = Date.parse(HAS_ZONE.test(value) ? value : `${value}Z`);
    return Number.isFinite(parsed) ? parsed : null;
};

/**
 * Current time on the server, estimated from this device's clock and the
 * measured offset. Phones with a wrong clock or time zone would otherwise
 * see server deadlines as already passed.
 */
export const serverNow = (clockOffset = 0, deviceNow: number = Date.now()): number => deviceNow + clockOffset;

export const millisecondsUntil = (
    date: string | null | undefined,
    clockOffset = 0,
    deviceNow: number = Date.now(),
): number | null => {
    const timestamp = parseUtcTimestamp(date);
    return timestamp === null ? null : timestamp - serverNow(clockOffset, deviceNow);
};

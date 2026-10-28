import {t} from "@lingui/macro";
import {Event, ProductPrice} from "../types.ts";
import {getClientLocale} from "../locales.ts";
import {getIncludedRefundProtection, isCheckoutRefundProtection} from "./refundProtection.ts";
import {getEventProducts} from "./embeddedRegistration.ts";

const DAY_MS = 24 * 60 * 60 * 1000;
const HOUR_MS = 60 * 60 * 1000;
const COUNTDOWN_MAX_DAYS = 7;
const EVENING_HOUR = 17;

/**
 * Amount shown to the buyer for one unit, like ProductPriceDisplay (included protection, taxes and fees only in INCLUSIVE mode).
 */
export const getDisplayUnitPrice = (price: ProductPrice, priceDisplayMode?: string): number => {
    let amount = Number(price.price) + getIncludedRefundProtection(price);
    if (priceDisplayMode === 'INCLUSIVE') {
        amount += (Number(price.tax_total) || 0) + (Number(price.fee_total) || 0);
    }
    return Math.round(amount * 100) / 100;
};

export const formatCompactCurrency = (amount: number, currency = 'EUR'): string => {
    const isWhole = Math.abs(amount - Math.round(amount)) < 0.005;
    return new Intl.NumberFormat(getClientLocale(), {
        style: 'currency',
        currency,
        minimumFractionDigits: isWhole ? 0 : 2,
        maximumFractionDigits: isWhole ? 0 : 2,
    }).format(amount);
};

/**
 * Lowest price a visitor can buy right now. Null when nothing is on sale or a free ticket is available.
 */
export const getLowestAvailablePrice = (event: Event): number | null => {
    const amounts = getEventProducts(event)
        .filter(product => product.product_type === 'TICKET'
            && product.type !== 'DONATION'
            && !isCheckoutRefundProtection(product)
            && product.is_available
            && !product.is_sold_out)
        .flatMap(product => product.prices ?? [])
        .filter(price => price.is_available !== false && !price.is_sold_out && !price.is_before_sale_start_date && !price.is_after_sale_end_date)
        .map(price => getDisplayUnitPrice(price, event.settings?.price_display_mode));

    if (amounts.length === 0) {
        return null;
    }

    const lowest = Math.min(...amounts);
    return lowest > 0 ? lowest : null;
};

const zonedParts = (date: Date, timeZone: string) => {
    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(date);
    const get = (type: string) => Number(parts.find(part => part.type === type)?.value);
    return {
        dayIndex: Math.floor(Date.UTC(get('year'), get('month') - 1, get('day')) / DAY_MS),
        hour: get('hour'),
        minute: get('minute'),
    };
};

const formatStartTime = (hour: number, minute: number, date: Date, timeZone: string): string => {
    if (getClientLocale().startsWith('fr')) {
        return minute > 0 ? `${hour}h${String(minute).padStart(2, '0')}` : `${hour}h`;
    }
    return new Intl.DateTimeFormat(getClientLocale(), {timeZone, hour: 'numeric', minute: '2-digit'}).format(date);
};

/**
 * Short, real countdown to the event start, only when it starts within 7 days. Client side only (depends on now).
 */
export const formatEventCountdown = (startDate: string | undefined, timeZone: string | undefined, now: Date): string | null => {
    if (!startDate) {
        return null;
    }

    const isoDate = startDate.replace(' ', 'T');
    const start = new Date(/([zZ]|[+-]\d{2}:?\d{2})$/.test(isoDate) ? isoDate : `${isoDate}Z`);
    const remaining = start.getTime() - now.getTime();
    if (Number.isNaN(remaining) || remaining <= 0 || remaining > COUNTDOWN_MAX_DAYS * DAY_MS) {
        return null;
    }

    const zone = timeZone || 'UTC';
    let startParts;
    let nowParts;
    try {
        startParts = zonedParts(start, zone);
        nowParts = zonedParts(now, zone);
    } catch {
        return null;
    }

    const time = formatStartTime(startParts.hour, startParts.minute, start, zone);
    const daysApart = startParts.dayIndex - nowParts.dayIndex;

    if (daysApart === 0) {
        return startParts.hour >= EVENING_HOUR ? t`Tonight ${time}` : t`Today ${time}`;
    }
    if (daysApart === 1) {
        return t`Tomorrow ${time}`;
    }

    const days = Math.floor(remaining / DAY_MS);
    const hours = Math.floor((remaining % DAY_MS) / HOUR_MS);
    return hours > 0 ? t`In ${days} d ${hours} h` : t`In ${days} d`;
};

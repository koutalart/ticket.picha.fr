import {I18n} from "@lingui/core";
import {t} from "@lingui/macro";
import {Event} from "../../../types.ts";
import {eventCoverImage} from "../../../utilites/urlHelper.ts";
import {getLowestAvailablePrice} from "../../../utilites/eventConversion.ts";
import classes from "./LiveChatWindow.module.scss";

interface LiveChatEventBarProps {
    event: Event;
    i18n: I18n;
    onBook: () => void;
}

const CHAT_LOCALE = 'fr-FR';

const format = (date: string, timeZone: string | undefined, options: Intl.DateTimeFormatOptions): string => {
    try {
        return new Intl.DateTimeFormat(CHAT_LOCALE, {...options, timeZone}).format(new Date(date));
    } catch {
        return new Intl.DateTimeFormat(CHAT_LOCALE, options).format(new Date(date));
    }
};

const formatPrice = (amount: number, currency = 'EUR'): string => {
    const isWhole = Math.abs(amount - Math.round(amount)) < 0.005;
    return new Intl.NumberFormat(CHAT_LOCALE, {
        style: 'currency',
        currency,
        minimumFractionDigits: isWhole ? 0 : 2,
        maximumFractionDigits: isWhole ? 0 : 2,
    }).format(amount);
};

/**
 * The event of the page, always in sight under the chat header, with a button straight to the ticket form.
 * The price is the lowest one on sale right now, from the event data.
 */
export const LiveChatEventBar = ({event, i18n, onBook}: LiveChatEventBarProps) => {
    const cover = eventCoverImage(event);
    const lowestPrice = getLowestAvailablePrice(event);
    const priceLabel = lowestPrice !== null ? formatPrice(lowestPrice, event.currency) : null;
    const location = event.settings?.location_details;
    const place = event.settings?.is_online_event ? null : (location?.venue_name || location?.city || null);
    const day = event.start_date ? format(event.start_date, event.timezone, {weekday: 'short', day: 'numeric', month: 'short'}) : '';
    const time = event.start_date ? format(event.start_date, event.timezone, {hour: '2-digit', minute: '2-digit'}) : '';
    const details = [day, time, place].filter(Boolean).join(', ');

    return (
        <div className={classes.eventBar}>
            {cover?.url && (
                <img
                    src={cover.url}
                    alt=""
                    className={classes.eventBarThumb}
                    loading="lazy"
                    decoding="async"
                />
            )}
            <div className={classes.eventBarText}>
                <span className={classes.eventBarTitle}>{event.title}</span>
                {details && <span className={classes.eventBarDetails}>{details}</span>}
            </div>
            <button type="button" className={classes.eventBarCta} onClick={onBook}>
                {priceLabel ? t(i18n)`Book, from ${priceLabel}` : t(i18n)`Book`}
            </button>
        </div>
    );
};

export default LiveChatEventBar;

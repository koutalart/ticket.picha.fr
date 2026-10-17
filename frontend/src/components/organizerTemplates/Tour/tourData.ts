import dayjs from "dayjs";
import {Event} from "../../../types.ts";
import {getClientLocale} from "../../../locales.ts";
import {formatDateWithLocale} from "../../../utilites/dates.ts";
import {getLowestPrice, getTicketTiers, getVenue} from "../Poster/eventData.ts";

const CITY_ATTRIBUTE_NAMES = ['ville', 'city', 'tour_city'];
const DEFAULT_THEME_ACCENT = '#8b5cf6';

export const TOUR_FALLBACK_ACCENT = '#ffd23f';

/**
 * City shown in the tour line-up. A public event attribute named "ville" / "city" wins,
 * so a venue outside the city (e.g. a suburb) can still be announced under the tour city.
 */
export const tourCity = (event: Event): string => {
    const attribute = (event.attributes || []).find(item => CITY_ATTRIBUTE_NAMES.includes(item.name.trim().toLowerCase()));
    return (attribute?.value || getVenue(event).city || event.title).toUpperCase();
};

export const eventAccent = (event: Event, fallback: string): string => {
    const accent = event.settings?.homepage_theme_settings?.accent;
    return accent && accent.toLowerCase() !== DEFAULT_THEME_ACCENT ? accent : fallback;
};

const shortLabel = (value: string) => value.replace(/\.$/, '').toUpperCase();

export const dateBlock = (event: Event) => {
    const start = dayjs.utc(event.start_date).tz(event.timezone).locale(getClientLocale());
    return {
        weekday: shortLabel(start.format('ddd')),
        day: start.format('DD'),
        month: shortLabel(start.format('MMM')),
        monthYear: start.format('MMMM YYYY'),
        time: formatDateWithLocale(event.start_date, 'timeOnly', event.timezone),
    };
};

export const tourPrice = (event: Event) => {
    const tiers = getTicketTiers(event);
    return {
        lowest: getLowestPrice(tiers),
        soldOut: tiers.length > 0 && tiers.every(tier => tier.soldOut),
    };
};

export const eventFlyer = (event: Event) => event.images?.find(image => image.type === 'EVENT_FLYER');

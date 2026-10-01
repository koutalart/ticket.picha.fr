import {t} from "@lingui/macro";
import {Event, Product} from "../../../types.ts";
import {formatCurrency} from "../../../utilites/currency.ts";
import {getProductsFromEvent} from "../../../utilites/helpers.ts";
import {formatAddress} from "../../../utilites/addressUtilities.ts";

export interface TicketTier {
    id: string;
    name: string;
    price: number;
    currency: string;
    soldOut: boolean;
    notYetOnSale: boolean;
    highlight?: string;
}

const isVisibleTicket = (product: Product) => !product.is_hidden
    && !product.is_hidden_without_promo_code
    && (!product.product_type || product.product_type === 'TICKET');

export const getTicketTiers = (event: Event): TicketTier[] => {
    return (getProductsFromEvent(event) || []).filter(isVisibleTicket).flatMap(product => {
        const prices = product.prices && product.prices.length > 0 ? product.prices : [{price: product.price || 0}];

        return prices
            .filter(price => !('is_hidden' in price && price.is_hidden))
            .map((price, index) => ({
                id: `${product.id}-${'id' in price && price.id ? price.id : index}`,
                name: 'label' in price && price.label ? `${product.title} · ${price.label}` : product.title,
                price: price.price || 0,
                currency: event.currency || 'EUR',
                soldOut: Boolean(product.is_sold_out || ('is_sold_out' in price && price.is_sold_out)),
                notYetOnSale: Boolean(product.is_before_sale_start_date),
                highlight: product.is_highlighted ? (product.highlight_message || t`Most popular`) : undefined,
            }));
    });
};

export const getLowestPrice = (tiers: TicketTier[]): number | null => {
    const available = tiers.filter(tier => !tier.soldOut);
    const pool = available.length > 0 ? available : tiers;
    return pool.length > 0 ? Math.min(...pool.map(tier => tier.price)) : null;
};

export const formatFromPrice = (tiers: TicketTier[], currency?: string): string | null => {
    const lowest = getLowestPrice(tiers);
    if (lowest === null) {
        return null;
    }
    if (lowest === 0) {
        return t`Free`;
    }
    const highest = Math.max(...tiers.map(tier => tier.price));
    const formatted = formatCurrency(lowest, currency);
    return highest > lowest ? t`from ${formatted}` : formatted;
};

export const getVenue = (event: Event) => {
    const details = event.settings?.location_details;
    return {
        name: details?.venue_name,
        city: details?.city,
        full: details ? formatAddress(details) : undefined,
        mapsUrl: details ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(formatAddress(details))}` : undefined,
        isOnline: Boolean(event.settings?.is_online_event),
    };
};

export const getCoverUrl = (event: Event) => event.images?.find(image => image.type === 'EVENT_COVER')?.url;

const toIso = (date?: string) => date ? `${date.replace(' ', 'T')}${date.endsWith('Z') ? '' : 'Z'}` : undefined;

export const buildEventJsonLd = (event: Event, url: string, organizerName: string) => {
    const tiers = getTicketTiers(event);
    const venue = getVenue(event);
    const lowest = getLowestPrice(tiers);
    const details = event.settings?.location_details;

    return {
        '@type': 'Event',
        name: event.title,
        startDate: toIso(event.start_date),
        endDate: toIso(event.end_date),
        eventStatus: 'https://schema.org/EventScheduled',
        eventAttendanceMode: venue.isOnline
            ? 'https://schema.org/OnlineEventAttendanceMode'
            : 'https://schema.org/OfflineEventAttendanceMode',
        image: getCoverUrl(event),
        description: event.description_preview,
        url,
        location: venue.isOnline ? {'@type': 'VirtualLocation', url} : {
            '@type': 'Place',
            name: venue.name || venue.city,
            address: {
                '@type': 'PostalAddress',
                streetAddress: details?.address_line_1,
                addressLocality: details?.city,
                postalCode: details?.zip_or_postal_code,
                addressCountry: details?.country,
            },
        },
        organizer: {'@type': 'Organization', name: organizerName},
        offers: lowest === null ? undefined : {
            '@type': 'AggregateOffer',
            url,
            priceCurrency: event.currency,
            lowPrice: lowest,
            highPrice: Math.max(...tiers.map(tier => tier.price)),
            availability: tiers.every(tier => tier.soldOut)
                ? 'https://schema.org/SoldOut'
                : 'https://schema.org/InStock',
        },
    };
};

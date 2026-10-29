/* eslint-disable lingui/no-unlocalized-strings */
import dayjs from "dayjs";
import utc from "dayjs/plugin/utc";
import timezone from "dayjs/plugin/timezone";
import {Event, Product, ProductPrice, SCHEMA_EVENT_TYPES, SchemaEventType} from "../types.ts";
import {withIncludedRefundProtection} from "./refundProtection.ts";
import {getOrganizerSocialProfiles} from "./organizerSocialLinks.ts";

dayjs.extend(utc);
dayjs.extend(timezone);

const SCHEMA = "https://schema.org";

export interface EventStructuredDataUrls {
    eventUrl: string;
    organizerUrl?: string;
}

type JsonLd = Record<string, unknown>;

export const toIsoInTimezone = (date: string | Date | undefined | null, tz?: string): string | undefined => {
    if (!date) {
        return undefined;
    }
    const parsed = dayjs.utc(date);
    if (!parsed.isValid()) {
        return undefined;
    }
    try {
        return (tz ? parsed.tz(tz) : parsed).format("YYYY-MM-DDTHH:mm:ssZ");
    } catch {
        return parsed.format("YYYY-MM-DDTHH:mm:ss[Z]");
    }
};

export const getStructuredDataProducts = (event: Event): Product[] =>
    (event.products?.length ? event.products : event.product_categories?.flatMap(category => category.products || []) || [])
        .filter(Boolean);

const isPubliclyOffered = (product: Product) =>
    !product.is_refund_protection && !product.is_hidden && !product.is_hidden_without_promo_code;

/**
 * What a visitor can actually buy as entry: tickets, never the cancellation protection, hidden products
 * or add-ons. Events selling only non-ticket products (registrations set up as GENERAL) keep those.
 */
export const getOfferProducts = (event: Event): Product[] => {
    const products = getStructuredDataProducts(event).filter(isPubliclyOffered);
    const tickets = products.filter(product => !product.product_type || product.product_type === "TICKET");
    return tickets.length ? tickets : products;
};

const isPaid = (product: Product) =>
    product.type === "DONATION" || !!product.prices?.some(price => Number(price.price) > 0);

export const isEventFree = (event: Event): boolean => {
    const products = getOfferProducts(event);
    return products.length > 0 && products.every(product => !isPaid(product));
};

export const isEventFullyPaid = (event: Event): boolean => {
    const products = getOfferProducts(event);
    return products.length > 0 && products.every(product =>
        product.type !== "DONATION"
        && !!product.prices?.length
        && product.prices.every(price => Number(price.price) > 0)
    );
};

/**
 * Lowest entry price paid by the buyer (mandatory protection included), ignoring sold out prices
 * while others remain on sale, or null.
 */
export const getLowestOfferPrice = (event: Event): number | null => {
    const prices = getOfferProducts(event)
        .flatMap(product => (product.prices || []).map(price => ({
            amount: withIncludedRefundProtection(Number(price.price), price),
            soldOut: !!(price.is_sold_out || product.is_sold_out),
        })))
        .filter(price => Number.isFinite(price.amount) && price.amount > 0);
    const onSale = prices.filter(price => !price.soldOut);
    const pool = onSale.length ? onSale : prices;
    return pool.length ? Math.min(...pool.map(price => price.amount)) : null;
};

const EMOJI_PATTERN = /[\p{Extended_Pictographic}\p{Regional_Indicator}\u{1F3FB}-\u{1F3FF}\u{FE0F}\u{20E3}\u{200D}]/gu;

export const stripEmojis = (value?: string | null): string =>
    String(value ?? "").replace(EMOJI_PATTERN, "").replace(/\s{2,}/g, " ").trim();

const escapeRegExp = (value: string) => value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

/**
 * Event name for search engines and LLMs: the SEO title without the " | Billets" or " | <organizer>"
 * suffixes added for the browser tab, falling back to the event title.
 */
export const getEventSeoName = (event: Event, organizerName = event.organizer?.name): string => {
    const title = stripEmojis(event.title) || event.title;
    const seoTitle = stripEmojis(event.settings?.seo_title);
    if (!seoTitle) {
        return title;
    }
    const organizer = stripEmojis(organizerName);
    const suffixes = [
        /^billet(s|terie)?\b.*$/i,
        /^picha( ticket)?$/i,
        ...(organizer ? [new RegExp(`^${escapeRegExp(organizer)}$`, "i")] : []),
    ];
    const parts = seoTitle.split(/\s+[|]\s+/);
    while (parts.length > 1 && suffixes.some(pattern => pattern.test(parts[parts.length - 1].trim()))) {
        parts.pop();
    }
    return parts.join(" | ").trim() || title;
};

export const resolveSchemaEventType = (value?: string | null): SchemaEventType =>
    SCHEMA_EVENT_TYPES.includes(value as SchemaEventType) ? value as SchemaEventType : "Event";

const availability = (product: Product, price?: ProductPrice): string => {
    const soldOut = !!(price?.is_sold_out || product.is_sold_out);
    const beforeStart = price?.is_before_sale_start_date ?? product.is_before_sale_start_date;
    const afterEnd = price?.is_after_sale_end_date ?? product.is_after_sale_end_date;
    const available = price?.is_available ?? product.is_available;

    if (soldOut) {
        return `${SCHEMA}/SoldOut`;
    }
    if (beforeStart) {
        return `${SCHEMA}/PreSale`;
    }
    if (afterEnd) {
        return `${SCHEMA}/Discontinued`;
    }
    return available === false ? `${SCHEMA}/SoldOut` : `${SCHEMA}/InStock`;
};

const compact = <T extends JsonLd>(value: T): T => {
    Object.keys(value).forEach(key => {
        const item = value[key];
        if (item === undefined || item === null || item === "" || (Array.isArray(item) && item.length === 0)) {
            delete value[key];
        }
    });
    return value;
};

const buildOffers = (event: Event, eventUrl: string): JsonLd[] =>
    getOfferProducts(event).flatMap(product => {
        const prices = product.prices?.length ? product.prices : [undefined];
        return prices.map(price => {
            const amount = withIncludedRefundProtection(Number(price?.price ?? 0), price);
            return compact({
                "@type": "Offer",
                name: stripEmojis(price?.label ? `${product.title} ${price.label}` : product.title) || undefined,
                url: eventUrl,
                price: Number.isFinite(amount) && amount > 0 ? amount : 0,
                priceCurrency: event.currency,
                validFrom: toIsoInTimezone(price?.sale_start_date ?? product.sale_start_date, event.timezone),
                availability: availability(product, price),
            });
        });
    });

const buildLocation = (event: Event, eventUrl: string): JsonLd | undefined => {
    if (event.settings?.is_online_event) {
        return {"@type": "VirtualLocation", url: eventUrl};
    }

    const details = event.settings?.location_details;
    if (!details) {
        return undefined;
    }

    const address = compact({
        "@type": "PostalAddress",
        streetAddress: [details.address_line_1, details.address_line_2].filter(Boolean).join(", ") || undefined,
        addressLocality: details.city,
        addressRegion: details.state_or_region,
        postalCode: details.zip_or_postal_code,
        addressCountry: details.country,
    });

    if (Object.keys(address).length <= 1 && !details.venue_name) {
        return undefined;
    }

    return compact({
        "@type": "Place",
        name: details.venue_name || details.city,
        address: Object.keys(address).length > 1 ? address : undefined,
    });
};

export const getEventImageUrls = (event: Event): string[] => {
    const order = ["EVENT_SHARE_IMAGE", "EVENT_COVER", "EVENT_FLYER"];
    const urls = order
        .map(type => event.images?.find(image => image.type === type)?.url)
        .filter((url): url is string => !!url);
    return Array.from(new Set(urls));
};

const getOrganizerSameAs = (event: Event, organizerUrl?: string): string[] => {
    const organizer = event.organizer;
    const links = [
        organizer?.website,
        organizer?.settings?.website_url,
        ...getOrganizerSocialProfiles(organizer?.settings).map(profile => profile.url),
    ].filter((url): url is string => !!url && /^https?:\/\//i.test(url) && url !== organizerUrl);
    return Array.from(new Set(links));
};

export const buildEventJsonLd = (event: Event, urls: EventStructuredDataUrls): JsonLd => {
    const settings = event.settings;
    const organizer = event.organizer;
    const name = getEventSeoName(event);
    const plainTitle = stripEmojis(event.title) || event.title;

    return compact({
        "@context": SCHEMA,
        "@type": resolveSchemaEventType(settings?.schema_event_type),
        name,
        alternateName: plainTitle !== name ? plainTitle : undefined,
        description: settings?.seo_description || event.description_preview,
        startDate: toIsoInTimezone(event.start_date, event.timezone),
        endDate: toIsoInTimezone(event.end_date, event.timezone),
        eventStatus: `${SCHEMA}/EventScheduled`,
        eventAttendanceMode: settings?.is_online_event
            ? `${SCHEMA}/OnlineEventAttendanceMode`
            : `${SCHEMA}/OfflineEventAttendanceMode`,
        location: buildLocation(event, urls.eventUrl),
        image: getEventImageUrls(event),
        keywords: settings?.seo_keywords,
        url: urls.eventUrl,
        isAccessibleForFree: isEventFree(event) ? true : (isEventFullyPaid(event) ? false : undefined),
        organizer: organizer ? compact({
            "@type": "Organization",
            name: organizer.name,
            url: urls.organizerUrl || organizer.website,
            sameAs: getOrganizerSameAs(event, urls.organizerUrl),
        }) : undefined,
        offers: buildOffers(event, urls.eventUrl),
    });
};

export const buildEventBreadcrumbJsonLd = (event: Event, urls: EventStructuredDataUrls): JsonLd => {
    const items = [
        ...(event.organizer?.name && urls.organizerUrl ? [{name: event.organizer.name, item: urls.organizerUrl}] : []),
        {name: event.title, item: urls.eventUrl},
    ];

    return {
        "@context": SCHEMA,
        "@type": "BreadcrumbList",
        itemListElement: items.map((entry, index) => ({
            "@type": "ListItem",
            position: index + 1,
            name: entry.name,
            item: entry.item,
        })),
    };
};

export const serializeJsonLd = (data: JsonLd): string =>
    JSON.stringify(data)
        .replace(/</g, "\\u003c")
        .replace(/>/g, "\\u003e")
        .replace(/&/g, "\\u0026");

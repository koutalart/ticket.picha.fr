import {t} from "@lingui/macro";
import classes from "./EventFactSummary.module.scss";
import {Event, Product} from "../../../types.ts";
import {formatEventDateRange} from "../../../utilites/dates.ts";
import {formatAddress, isAddressSet} from "../../../utilites/addressUtilities.ts";
import {formatCurrency} from "../../../utilites/currency.ts";
import {getStructuredDataProducts} from "../../../utilites/eventStructuredData.ts";
import {withIncludedRefundProtection} from "../../../utilites/refundProtection.ts";

interface EventFactSummaryProps {
    event: Event;
    eventUrl: string;
}

const getTicketProducts = (event: Event): Product[] =>
    getStructuredDataProducts(event)
        .filter(product => product.product_type === 'TICKET' && !product.is_refund_protection);

const lowestTicketPrice = (tickets: Product[]): number | null => {
    const prices = tickets
        .flatMap(product => product.prices || [])
        .filter(price => Number(price.price) > 0)
        .map(price => withIncludedRefundProtection(Number(price.price), price))
        .filter(price => Number.isFinite(price) && price > 0);
    return prices.length ? Math.min(...prices) : null;
};

const areAllTicketsFree = (tickets: Product[]): boolean =>
    tickets.length > 0 && tickets.every(product =>
        product.type !== 'DONATION' && !(product.prices || []).some(price => Number(price.price) > 0)
    );

export const EventFactSummary = ({event, eventUrl}: EventFactSummaryProps) => {
    const location = event.settings?.location_details;
    const venue = [location?.venue_name, isAddressSet(location) ? formatAddress(location!) : null]
        .filter(Boolean)
        .join(", ");
    const tickets = getTicketProducts(event);
    const minPrice = lowestTicketPrice(tickets);
    const dateRange = formatEventDateRange(event.start_date, event.end_date, event.timezone);
    const organizerName = event.organizer?.name;
    const registrationUrl = `${eventUrl}#tickets`;

    const formattedMinPrice = minPrice !== null ? formatCurrency(minPrice, event.currency) : null;
    const price = areAllTicketsFree(tickets)
        ? t`Free, registration required`
        : formattedMinPrice !== null
            ? t({message: `From ${formattedMinPrice}`, context: 'lowest ticket price'})
            : null;

    return (
        <section className={classes.summary} aria-label={t`Key information about the event`}>
            <h2>{t`Key information`}</h2>
            <ul>
                <li>{t`Event: ${event.title}`}</li>
                {organizerName && <li>{t`Organizer: ${organizerName}`}</li>}
                <li>{t`Date: ${dateRange}`}</li>
                {event.settings?.is_online_event
                    ? <li>{t`Location: online event`}</li>
                    : venue && <li>{t`Location: ${venue}`}</li>}
                {price && <li>{t`Price: ${price}`}</li>}
                <li>
                    {t`How to register: fill in the form in the tickets section of this page`}{" "}
                    <a href={registrationUrl}>{registrationUrl}</a>
                </li>
            </ul>
        </section>
    );
};

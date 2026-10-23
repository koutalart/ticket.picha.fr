import {t} from "@lingui/macro";
import classes from "./EventFactSummary.module.scss";
import {Event} from "../../../types.ts";
import {formatEventDateRange} from "../../../utilites/dates.ts";
import {formatAddress, isAddressSet} from "../../../utilites/addressUtilities.ts";
import {formatCurrency} from "../../../utilites/currency.ts";
import {getStructuredDataProducts, isEventFree} from "../../../utilites/eventStructuredData.ts";

interface EventFactSummaryProps {
    event: Event;
    eventUrl: string;
}

const lowestPrice = (event: Event): number | null => {
    const prices = getStructuredDataProducts(event)
        .flatMap(product => product.prices || [])
        .map(price => Number(price.price))
        .filter(price => Number.isFinite(price) && price > 0);
    return prices.length ? Math.min(...prices) : null;
};

export const EventFactSummary = ({event, eventUrl}: EventFactSummaryProps) => {
    const location = event.settings?.location_details;
    const venue = [location?.venue_name, isAddressSet(location) ? formatAddress(location!) : null]
        .filter(Boolean)
        .join(", ");
    const minPrice = lowestPrice(event);
    const dateRange = formatEventDateRange(event.start_date, event.end_date, event.timezone);
    const organizerName = event.organizer?.name;
    const registrationUrl = `${eventUrl}#tickets`;

    const price = isEventFree(event)
        ? t`Free, registration required`
        : minPrice !== null
            ? t`From ${formatCurrency(minPrice, event.currency)}`
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

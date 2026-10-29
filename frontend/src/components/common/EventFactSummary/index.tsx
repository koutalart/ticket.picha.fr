import {t} from "@lingui/macro";
import classes from "./EventFactSummary.module.scss";
import {Event} from "../../../types.ts";
import {formatEventDateRange} from "../../../utilites/dates.ts";
import {formatAddress, isAddressSet} from "../../../utilites/addressUtilities.ts";
import {formatCurrency} from "../../../utilites/currency.ts";
import {getLowestOfferPrice, isEventFree} from "../../../utilites/eventStructuredData.ts";

interface EventFactSummaryProps {
    event: Event;
    eventUrl: string;
}

export const EventFactSummary = ({event, eventUrl}: EventFactSummaryProps) => {
    const location = event.settings?.location_details;
    const venue = [location?.venue_name, isAddressSet(location) ? formatAddress(location!) : null]
        .filter(Boolean)
        .join(", ");
    const minPrice = getLowestOfferPrice(event);
    const dateRange = formatEventDateRange(event.start_date, event.end_date, event.timezone);
    const organizerName = event.organizer?.name;
    const registrationUrl = `${eventUrl}#tickets`;

    const formattedMinPrice = minPrice !== null ? formatCurrency(minPrice, event.currency) : null;
    const price = isEventFree(event)
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

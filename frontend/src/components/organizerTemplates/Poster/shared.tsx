import React from "react";
import {Link} from "react-router";
import {t} from "@lingui/macro";
import {IconArrowRight, IconMapPin} from "@tabler/icons-react";
import {Event, GenericPaginatedResponse, Organizer} from "../../../types.ts";
import {formatDateWithLocale} from "../../../utilites/dates.ts";
import {eventHomepagePath} from "../../../utilites/urlHelper.ts";
import {formatFromPrice, getCoverUrl, getTicketTiers, getVenue, TicketTier} from "./eventData.ts";
import type {OrganizerSitePage} from "../../../routeLoaders/organizerSitePageLoader.ts";
import classes from "./Poster.module.scss";

export interface PosterPageProps {
    organizer: Organizer;
    eventsData?: GenericPaginatedResponse<Event>;
    pastEventsData?: GenericPaginatedResponse<Event> | null;
    isPastEvents: boolean;
    featured?: Event;
    tiers: TicketTier[];
    fromPrice: string | null;
    sitePath: (page: OrganizerSitePage) => string;
    openContact: () => void;
}

export const longDate = (event: Event) => formatDateWithLocale(event.start_date, 'dayName', event.timezone);
export const time = (date: string, event: Event) => formatDateWithLocale(date, 'timeOnly', event.timezone);

export const PosterEventCard = ({event, past = false}: { event: Event; past?: boolean }) => {
    const cover = getCoverUrl(event);
    const price = past ? null : formatFromPrice(getTicketTiers(event), event.currency);
    const venue = getVenue(event);

    return (
        <Link to={eventHomepagePath(event)} className={classes.card}>
            <div className={classes.cardPoster}>
                {cover
                    ? <img src={cover} alt={event.title} loading="lazy"/>
                    : <div className={classes.cardPlaceholder}>{event.title}</div>}
                <div className={classes.dateChip}>
                    <span className={classes.dateChipDay}>
                        {formatDateWithLocale(event.start_date, 'dayOfMonth', event.timezone)}
                    </span>
                    <span className={classes.dateChipMonth}>
                        {formatDateWithLocale(event.start_date, 'monthShort', event.timezone)}
                    </span>
                </div>
            </div>
            <div className={classes.cardBody}>
                <h3 className={classes.cardTitle}>{event.title}</h3>
                {(venue.city || venue.name) && (
                    <p className={classes.cardMeta}><IconMapPin size={15}/>{venue.city || venue.name}</p>
                )}
                <div className={classes.cardFooter}>
                    {price && <span className={classes.cardPrice}>{price}</span>}
                    <span className={classes.cardCta}>
                        {past ? t`See the event` : t`Tickets`} <IconArrowRight size={16}/>
                    </span>
                </div>
            </div>
        </Link>
    );
};

export const PageIntro = ({heading, title, lead, children}: {
    heading: string;
    title: string;
    lead?: string | null;
    children?: React.ReactNode;
}) => (
    <section className={classes.pageIntro} aria-labelledby="page-title">
        <div className={classes.pageIntroGlow} aria-hidden="true"/>
        <div className={classes.pageIntroInner}>
            <h1 id="page-title" className={classes.eyebrow}>{heading}</h1>
            <p className={classes.pageTitle}>{title}</p>
            {lead && <p className={classes.pageLead}>{lead}</p>}
            {children}
        </div>
    </section>
);

export const SectionHead = ({id, eyebrow, title}: { id: string; eyebrow: string; title: string }) => (
    <div className={classes.sectionHead}>
        <p className={classes.eyebrow}>{eyebrow}</p>
        <h2 id={id} className={classes.sectionTitle}>{title}</h2>
    </div>
);

export const StatsBand = ({stats}: { stats: { value: string; label: string }[] }) => (
    <dl className={classes.stats}>
        {stats.map(stat => (
            <div key={`${stat.value}-${stat.label}`} className={classes.stat}>
                <dt>{stat.label}</dt>
                <dd>{stat.value}</dd>
            </div>
        ))}
    </dl>
);

export const PartnerLogos = ({partners}: { partners: NonNullable<Organizer['site_content']>['partners'] }) => (
    <ul className={classes.partnerGrid}>
        {(partners || []).map(partner => {
            const content = partner.logo_url
                ? <img src={partner.logo_url} alt={partner.name} loading="lazy"/>
                : <span>{partner.name}</span>;
            return (
                <li key={partner.name} className={classes.partner}>
                    {partner.url
                        ? <a href={partner.url} target="_blank" rel="noopener noreferrer" aria-label={partner.name}>{content}</a>
                        : content}
                </li>
            );
        })}
    </ul>
);

export const areaSuffix = (organizer: Organizer) => {
    const area = organizer.site_content?.area;
    return area ? ` ${t`in ${area}`}` : '';
};

export const stripHtml = (html?: string | null) => (html || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();

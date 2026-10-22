/* eslint-disable lingui/no-unlocalized-strings */
import {Helmet} from "react-helmet-async";
import {t} from "@lingui/macro";
import {Event, EventPages, EventPageSlug} from "../../../types.ts";
import {eventHomepageUrl, eventShareImageUrl} from "../../../utilites/urlHelper.ts";
import {utcToTz} from "../../../utilites/dates.ts";
import {
    artistAnchor,
    eventDedicatedPageUrl,
    eventPageLabel,
    htmlToText,
    jsonLdScript,
    truncateText,
} from "../../../utilites/eventPages.ts";

interface EventDedicatedPageHeadProps {
    event: Event;
    pages: EventPages;
    page: EventPageSlug;
}

const pageDescription = (event: Event, pages: EventPages, page: EventPageSlug) => {
    const title = event.title;

    switch (page) {
        case 'artistes': {
            const names = pages.artists.map((artist) => artist.name).join(', ');
            return t`Discover the artists of ${title}: ${names}. Biographies, performed songs and social networks.`;
        }
        case 'infos-pratiques': {
            const sections = pages.practical_info.map((section) => section.title.toLowerCase()).join(', ');
            return t`Practical information for ${title}: ${sections}.`;
        }
        case 'faq': {
            const firstQuestion = pages.faq[0]?.question ?? '';
            return t`Frequently asked questions about ${title}. ${firstQuestion}`;
        }
    }
};

const eventLocation = (event: Event) => {
    const details = event.settings?.location_details;
    if (!details) {
        return undefined;
    }

    return {
        "@type": "Place",
        name: details.venue_name,
        address: {
            "@type": "PostalAddress",
            streetAddress: details.address_line_1,
            addressLocality: details.city,
            addressRegion: details.state_or_region,
            postalCode: details.zip_or_postal_code,
            addressCountry: details.country,
        },
    };
};

export const EventDedicatedPageHead = ({event, pages, page}: EventDedicatedPageHeadProps) => {
    const pageUrl = eventDedicatedPageUrl(event, page);
    const eventUrl = eventHomepageUrl(event);
    const label = eventPageLabel(page);
    const baseTitle = `${label} | ${event.settings?.seo_title || event.title} | ${event.organizer?.name}`;
    const title = event.status === 'DRAFT' ? 'DRAFT - ' + baseTitle : baseTitle;
    const description = truncateText(htmlToText(pageDescription(event, pages, page)), 160);
    const image = eventShareImageUrl(event);

    const breadcrumb = {
        "@context": "https://schema.org",
        "@type": "BreadcrumbList",
        itemListElement: [
            {"@type": "ListItem", position: 1, name: event.title, item: eventUrl},
            {"@type": "ListItem", position: 2, name: label, item: pageUrl},
        ],
    };

    const structuredData: object[] = [breadcrumb];

    if (page === 'artistes') {
        structuredData.push({
            "@context": "https://schema.org",
            "@type": "Event",
            name: event.title,
            url: eventUrl,
            startDate: utcToTz(new Date(event.start_date), event.timezone),
            endDate: event.end_date ? utcToTz(new Date(event.end_date), event.timezone) : undefined,
            eventStatus: "https://schema.org/EventScheduled",
            eventAttendanceMode: event.settings?.is_online_event
                ? "https://schema.org/OnlineEventAttendanceMode"
                : "https://schema.org/OfflineEventAttendanceMode",
            location: eventLocation(event),
            image: image ? [image] : undefined,
            organizer: event.organizer ? {
                "@type": "Organization",
                name: event.organizer.name,
                url: event.organizer.website || undefined,
            } : undefined,
            performer: pages.artists.map((artist) => ({
                "@type": artist.kind === 'GROUP' ? "MusicGroup" : "Person",
                name: artist.name,
                url: `${pageUrl}#${artistAnchor(artist)}`,
                image: artist.photo_url || undefined,
                description: artist.bio ? truncateText(htmlToText(artist.bio), 300) : undefined,
                sameAs: Object.values(artist.links || {}).filter(Boolean),
            })),
        });
    }

    if (page === 'faq') {
        structuredData.push({
            "@context": "https://schema.org",
            "@type": "FAQPage",
            mainEntity: pages.faq.map((item) => ({
                "@type": "Question",
                name: item.question,
                acceptedAnswer: {
                    "@type": "Answer",
                    text: item.answer,
                },
            })),
        });
    }

    return (
        <Helmet>
            <title>{title}</title>
            <meta name="description" content={description}/>
            <meta property="og:title" content={baseTitle}/>
            <meta property="og:description" content={description}/>
            {image && <meta property="og:image" content={image}/>}
            <meta property="og:url" content={pageUrl}/>
            <meta property="og:type" content="website"/>
            <meta name="twitter:card" content="summary_large_image"/>
            <meta name="twitter:title" content={baseTitle}/>
            <meta name="twitter:description" content={description}/>
            {image && <meta name="twitter:image" content={image}/>}
            <meta name="robots" content="index, follow"/>
            <link rel="canonical" href={pageUrl}/>
            {structuredData.map((data, index) => (
                <script key={index} type="application/ld+json">{jsonLdScript(data)}</script>
            ))}
        </Helmet>
    );
};

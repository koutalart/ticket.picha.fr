/* eslint-disable lingui/no-unlocalized-strings */
import {Helmet} from "react-helmet-async";
import {Event} from "../../../types";
import {eventHomepageUrl, eventShareImage, organizerCanonicalUrl} from "../../../utilites/urlHelper.ts";
import {OpenGraphExtraMeta} from "../OpenGraphExtraMeta";
import {
    buildEventBreadcrumbJsonLd,
    buildEventJsonLd,
    serializeJsonLd
} from "../../../utilites/eventStructuredData.ts";

interface EventDocumentHeadProps {
    event: Event;
}

export const EventDocumentHead = ({event}: EventDocumentHeadProps) => {
    const eventSettings = event.settings;
    const title = (eventSettings?.seo_title ?? event.title) + ' | ' + event.organizer?.name;
    const description = eventSettings?.seo_description ?? event.description_preview;
    const keywords = eventSettings?.seo_keywords;
    const shareImage = eventShareImage(event);
    const image = shareImage?.url;
    const url = eventHomepageUrl(event);
    const organizerUrl = event.organizer?.id && event.organizer?.slug
        ? organizerCanonicalUrl(event.organizer)
        : undefined;
    const structuredDataUrls = {eventUrl: url, organizerUrl};
    const eventJsonLd = serializeJsonLd(buildEventJsonLd(event, structuredDataUrls));
    const breadcrumbJsonLd = serializeJsonLd(buildEventBreadcrumbJsonLd(event, structuredDataUrls));

    return (
        <>
        <Helmet>
            <title>{event.status === 'DRAFT' ? 'DRAFT - ' + title : title}</title>
            <meta name="description" content={description}/>
            {keywords && <meta name="keywords" content={keywords}/>}
            <meta property="og:title" content={title}/>
            <meta property="og:description" content={description}/>
            {image && <meta property="og:image" content={image}/>}
            {url && <meta property="og:url" content={url}/>}
            <meta property="og:type" content="website"/>
            <meta name="author" content={event.organizer?.name}/>

            <meta name="twitter:title" content={title}/>
            <meta name="twitter:description" content={description}/>
            {image && <meta name="twitter:image" content={image}/>}
            <meta name="twitter:card" content="summary_large_image"/>

            <meta name="robots" content="index, follow"/>

            <link rel="canonical" href={url}/>

            <script type="application/ld+json">
                {eventJsonLd}
            </script>
            <script type="application/ld+json">
                {breadcrumbJsonLd}
            </script>
        </Helmet>
        <OpenGraphExtraMeta image={shareImage} imageAlt={event.title} siteName={event.organizer?.name}/>
        </>
    );
}

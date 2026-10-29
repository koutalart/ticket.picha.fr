import React, {useEffect} from "react";
import {Link, useLoaderData} from "react-router";
import {t} from "@lingui/macro";
import {IconArrowRight, IconCalendar, IconChevronRight, IconMapPin} from "@tabler/icons-react";
import {Event, EventPages, EventPageSlug, OrganizerStatus} from "../../../types.ts";
import {computeThemeVariables, validateThemeSettings} from "../../../utilites/themeUtils.ts";
import {ensureHomepageFontLoaded, ORGANIZER_FONT_ATTRIBUTE} from "../../../utilites/fontLoader.ts";
import {isOrganizerFontActive} from "../../../constants/homepageFonts.ts";
import {eventCoverImageUrl, eventHomepagePath, organizerHomepageUrl} from "../../../utilites/urlHelper.ts";
import {eventDedicatedPagePath, eventPageLabel, eventRegistrationPath} from "../../../utilites/eventPages.ts";
import {EventDateRange} from "../../common/EventDateRange";
import {PoweredByFooter} from "../../common/PoweredByFooter";
import {SsrLoaderStyle} from "../../common/SsrLoaderStyle";
import {ArtistsList, FaqList, PracticalInfoList} from "../../common/EventPagesContent";
import {EventImmersiveShell, useEventImmersive} from "../../common/EventImmersiveShell";
import {hasEventRichFooter} from "../../common/EventRichFooter";
import {EventDedicatedPageHead} from "./EventDedicatedPageHead.tsx";
import classes from "./EventDedicatedPage.module.scss";
import {organizerLogoUrl} from "../../../utilites/organizerLogo.ts";
import {useConsentBannerTheme} from "../../../utilites/consentBannerTheme.ts";
import {LiveChatLauncher} from "../../common/LiveChatWindow/LiveChatLauncher.tsx";

interface LoaderData {
    event: Event;
    pages: EventPages;
    page: EventPageSlug;
}

const pageHeading = (page: EventPageSlug) => {
    switch (page) {
        case 'artistes':
            return t`The artists`;
        case 'infos-pratiques':
            return t({message: 'Practical information', context: 'event dedicated page'});
        case 'faq':
            return t`Frequently asked questions`;
    }
};

const pageIntro = (page: EventPageSlug, title: string) => {
    switch (page) {
        case 'artistes':
            return t`Meet the artists who will be on stage for ${title}.`;
        case 'infos-pratiques':
            return t`Everything you need to know before coming to ${title}.`;
        case 'faq':
            return t`Answers to the most frequent questions about ${title}.`;
    }
};

const isFreeEvent = (event: Event) => (event.products || event.product_categories?.flatMap(c => c.products || []) || [])
    .every(product => !product.prices?.some(price => Number(price.price) > 0));

export const EventDedicatedPage = () => {
    const {event, pages, page} = useLoaderData() as LoaderData;

    const themeSettings = validateThemeSettings(event.settings?.homepage_theme_settings);
    const organizerFont = event.organizer?.custom_font;
    const cssVars = computeThemeVariables(themeSettings, organizerFont);
    const organizerFontActive = isOrganizerFontActive(themeSettings.font_family, organizerFont);
    const immersive = useEventImmersive(event);
    useConsentBannerTheme(immersive.enabled ? undefined : themeSettings.accent, themeSettings.mode, cssVars['--theme-font-family']);

    useEffect(() => {
        ensureHomepageFontLoaded(themeSettings.font_family, organizerFont);
    }, [themeSettings.font_family, organizerFont]);

    const themeStyles = {
        '--event-bg-color': themeSettings.background,
        '--event-content-bg-color': cssVars['--theme-surface'],
        '--event-primary-color': themeSettings.accent,
        '--event-primary-text-color': cssVars['--theme-text-primary'],
        '--event-secondary-color': cssVars['--theme-text-secondary'],
        '--event-secondary-text-color': cssVars['--theme-text-tertiary'],
        '--event-accent-contrast': cssVars['--theme-accent-contrast'],
        '--event-accent-soft': cssVars['--theme-accent-soft'],
        '--event-accent-muted': cssVars['--theme-accent-muted'],
        '--event-border-color': cssVars['--theme-border'],
        '--theme-font-family': cssVars['--theme-font-family'],
        fontFamily: cssVars['--theme-font-family'],
    } as React.CSSProperties;

    const immersiveVars = immersive.style as Record<string, string>;
    const immersiveStyles = {
        '--event-bg-color': 'transparent',
        '--event-content-bg-color': 'rgba(255, 255, 255, 0.05)',
        '--event-primary-color': immersive.accent,
        '--event-primary-text-color': '#ffffff',
        '--event-secondary-color': 'rgba(255, 255, 255, 0.74)',
        '--event-secondary-text-color': 'rgba(255, 255, 255, 0.56)',
        '--event-accent-contrast': immersiveVars['--immersive-accent-contrast'],
        '--event-accent-soft': immersiveVars['--immersive-accent-tint'],
        '--event-accent-muted': immersiveVars['--immersive-accent-tint'],
        '--event-border-color': 'rgba(255, 255, 255, 0.14)',
    } as React.CSSProperties;

    const organizer = event.organizer;
    const coverImage = eventCoverImageUrl(event);
    const brandLogo = event.images?.find((image) => image.type === 'EVENT_HERO_BRAND_LOGO')?.url
        ?? organizerLogoUrl(organizer?.images, 'dark');
    const location = event.settings?.location_details;
    const locationLabel = [location?.venue_name, location?.city].filter(Boolean).join(', ');
    const availablePages = pages.available_pages ?? [];
    const otherPages = hasEventRichFooter(event) ? [] : availablePages.filter((available) => available !== page);
    const ctaLabel = isFreeEvent(event)
        ? t({message: 'Register', context: 'event dedicated page cta'})
        : t`Get Tickets`;

    const tabsAndBody = (
        <>
            <nav className={classes.tabs} aria-label={t`Event pages`}>
                <div className={classes.tabsInner}>
                    <a href={eventHomepagePath(event)} className={classes.tab}>{t`Event`}</a>
                    {availablePages.map((available) => (
                        <Link
                            key={available}
                            to={eventDedicatedPagePath(event, available)}
                            className={classes.tab}
                            aria-current={available === page ? 'page' : undefined}
                        >
                            {eventPageLabel(available)}
                        </Link>
                    ))}
                </div>
            </nav>

            <div className={classes.body}>
                <p className={classes.intro}>{pageIntro(page, event.title)}</p>

                {page === 'artistes' && <ArtistsList artists={pages.artists}/>}
                {page === 'infos-pratiques' && <PracticalInfoList sections={pages.practical_info}/>}
                {page === 'faq' && <FaqList items={pages.faq}/>}

                <aside className={classes.ctaCard}>
                    <div>
                        <h2 className={classes.ctaTitle}>{event.title}</h2>
                        <p className={classes.ctaText}><EventDateRange event={event}/>{locationLabel ? `, ${locationLabel}` : ''}</p>
                    </div>
                    <a href={eventRegistrationPath(event)} className={classes.ctaButton}>
                        {ctaLabel}
                        <IconArrowRight size={18} aria-hidden="true"/>
                    </a>
                </aside>

                {otherPages.length > 0 && (
                    <nav className={classes.related} aria-label={t`See also`}>
                        <h2 className={classes.relatedTitle}>{t`See also`}</h2>
                        <div className={classes.relatedLinks}>
                            {otherPages.map((other) => (
                                <Link key={other} to={eventDedicatedPagePath(event, other)} className={classes.relatedLink}>
                                    <span>{eventPageLabel(other)}</span>
                                    <IconArrowRight size={18} aria-hidden="true"/>
                                </Link>
                            ))}
                            <a href={eventHomepagePath(event)} className={classes.relatedLink}>
                                <span>{t`Event page`}</span>
                                <IconArrowRight size={18} aria-hidden="true"/>
                            </a>
                        </div>
                    </nav>
                )}
            </div>
        </>
    );

    if (immersive.enabled) {
        return (
            <EventImmersiveShell event={event} width="wide">
                <EventDedicatedPageHead event={event} pages={pages} page={page}/>
                <div className={classes.immersive} style={immersiveStyles}>
                    <div className={classes.immersiveHeading}>
                        <p className={classes.heroEyebrow}>{event.title}</p>
                        <h1 className={classes.heroTitle}>{pageHeading(page)}</h1>
                        <div className={classes.heroMeta}>
                            <span><IconCalendar size={16} aria-hidden="true"/><EventDateRange event={event}/></span>
                            {locationLabel && !event.settings?.is_online_event && (
                                <span><IconMapPin size={16} aria-hidden="true"/>{locationLabel}</span>
                            )}
                        </div>
                    </div>
                    {tabsAndBody}
                </div>
                <LiveChatLauncher organizer={event.organizer} eventId={event.id}/>
            </EventImmersiveShell>
        );
    }

    return (
        <main
            className={classes.page}
            style={themeStyles}
            data-mode={themeSettings.mode}
            {...(organizerFontActive ? {[ORGANIZER_FONT_ATTRIBUTE]: ''} : {})}
        >
            <SsrLoaderStyle
                background={themeSettings.background}
                accent={themeSettings.accent}
                mode={themeSettings.mode}
                backgroundType="COLOR"
            />
            <EventDedicatedPageHead event={event} pages={pages} page={page}/>

            <header className={classes.hero}>
                {coverImage && <img src={coverImage} alt="" aria-hidden="true" className={classes.heroMedia}/>}
                <div className={classes.heroVeil}/>
                <div className={classes.heroInner}>
                    <div className={classes.heroTopBar}>
                        <a href={eventHomepagePath(event)} className={classes.heroBrand}>
                            {brandLogo
                                ? <img src={brandLogo} alt={organizer?.name || event.title}/>
                                : <span>{organizer?.name}</span>}
                        </a>
                        <a href={eventRegistrationPath(event)} className={classes.heroCta}>
                            {ctaLabel}
                            <IconArrowRight size={18} aria-hidden="true"/>
                        </a>
                    </div>

                    <nav className={classes.breadcrumb} aria-label={t`Breadcrumb`}>
                        <ol>
                            {organizer && organizer.status === OrganizerStatus.LIVE && (
                                <li>
                                    <a href={organizerHomepageUrl(organizer)}>{organizer.name}</a>
                                    <IconChevronRight size={14} aria-hidden="true"/>
                                </li>
                            )}
                            <li>
                                <a href={eventHomepagePath(event)}>{event.title}</a>
                                <IconChevronRight size={14} aria-hidden="true"/>
                            </li>
                            <li aria-current="page">{eventPageLabel(page)}</li>
                        </ol>
                    </nav>

                    <div className={classes.heroText}>
                        <p className={classes.heroEyebrow}>{event.title}</p>
                        <h1 className={classes.heroTitle}>{pageHeading(page)}</h1>
                        <div className={classes.heroMeta}>
                            <span><IconCalendar size={16} aria-hidden="true"/><EventDateRange event={event}/></span>
                            {locationLabel && !event.settings?.is_online_event && (
                                <span><IconMapPin size={16} aria-hidden="true"/>{locationLabel}</span>
                            )}
                        </div>
                    </div>
                </div>
            </header>

            {tabsAndBody}

            <footer className={classes.footer}>
                <PoweredByFooter/>
            </footer>
            <LiveChatLauncher organizer={event.organizer} eventId={event.id}/>
        </main>
    );
};

export default EventDedicatedPage;

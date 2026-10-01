import React, {useEffect, useState} from "react";
import {Link, useLocation} from "react-router";
import {Helmet} from "react-helmet-async";
import {t} from "@lingui/macro";
import {IconMail, IconMenu2, IconX} from "@tabler/icons-react";
import {Organizer} from "../../../types.ts";
import type {OrganizerTemplateProps} from "../index.ts";
import type {OrganizerSitePage} from "../../../routeLoaders/organizerSitePageLoader.ts";
import {PoweredByFooter} from "../../common/PoweredByFooter";
import {ContactOrganizerModal} from "../../common/ContactOrganizerModal";
import {CookieConsentBanner} from "../../common/CookieConsentBanner";
import {socialMediaConfig} from "../../../constants/socialMediaConfig";
import {useOrganizerTrackingPixels} from "../../../hooks/useOrganizerTrackingPixels";
import {getConfig} from "../../../utilites/config.ts";
import {eventHomepagePath, eventHomepageUrl, organizerHomepagePath} from "../../../utilites/urlHelper.ts";
import {buildEventJsonLd, formatFromPrice, getCoverUrl, getTicketTiers, getVenue} from "./eventData.ts";
import {getFaq} from "./faq.ts";
import {areaSuffix, longDate, PosterPageProps, stripHtml} from "./shared.tsx";
import {HomePage} from "./pages/HomePage.tsx";
import {UniversePage} from "./pages/UniversePage.tsx";
import {EventsPage} from "./pages/EventsPage.tsx";
import {PartnersPage} from "./pages/PartnersPage.tsx";
import {ServicesPage} from "./pages/ServicesPage.tsx";
import {ContactPage} from "./pages/ContactPage.tsx";
import classes from "./Poster.module.scss";

const DEFAULT_POSTER_ACCENT = '#E4173D';

const getAccent = (organizer: Organizer): string => {
    const settings = organizer.settings?.homepage_theme_settings as { accent?: string } | undefined;
    return settings?.accent && settings.accent.toLowerCase() !== '#8b5cf6' ? settings.accent : DEFAULT_POSTER_ACCENT;
};

export const PosterTemplate = ({
                                   organizer,
                                   eventsData,
                                   pastEventsData,
                                   isPastEvents = false,
                                   sitePage = 'home',
                                   siteBasePath,
                                   siteOrigin,
                               }: OrganizerTemplateProps) => {
    const location = useLocation();
    const [contactModalOpen, setContactModalOpen] = useState(false);
    const [menuOpen, setMenuOpen] = useState(false);
    const {consentPending, onConsent} = useOrganizerTrackingPixels(organizer.settings?.tracking_pixels);

    useEffect(() => setMenuOpen(false), [location.pathname]);

    const content = organizer.site_content || {};
    const accent = getAccent(organizer);
    const logo = organizer.images?.find(image => image.type === 'ORGANIZER_LOGO');
    const events = eventsData?.data || [];
    const isFirstPage = !eventsData || eventsData.meta.current_page === 1;
    const featured = !isPastEvents && isFirstPage ? events[0] : undefined;
    const tiers = featured ? getTicketTiers(featured) : [];
    const fromPrice = featured ? formatFromPrice(tiers, featured.currency) : null;
    const allSoldOut = tiers.length > 0 && tiers.every(tier => tier.soldOut);

    const basePath = siteBasePath ?? organizerHomepagePath(organizer);
    const sitePath = (page: OrganizerSitePage) => page === 'home' ? (basePath || '/') : `${basePath}/${page}`;
    const customDomain = organizer.custom_domain;
    const origin = customDomain ? `https://${customDomain}` : (siteOrigin || getConfig('VITE_FRONTEND_URL') as string);
    const pageUrl = (page: OrganizerSitePage) => customDomain
        ? `${origin}${page === 'home' ? '/' : `/${page}`}`
        : `${origin}${sitePath(page)}`;
    const canonicalUrl = pageUrl(sitePage);
    const openContact = () => setContactModalOpen(true);

    const hasServices = (content.services || []).length > 0;
    const navItems: { page: OrganizerSitePage; label: string }[] = [
        {page: 'home', label: t`Home`},
        {page: 'evenements', label: t`Events`},
        ...(hasServices ? [{page: 'services' as OrganizerSitePage, label: t`Services`}] : []),
        {page: 'a-propos', label: t`The universe`},
        {page: 'partenaires', label: t`Partners`},
        {page: 'contact', label: t`Contact`},
    ];

    const socialLinks = Object.entries(organizer.settings?.social_media_handles || {})
        .filter(([platform, handle]) => handle && socialMediaConfig[platform as keyof typeof socialMediaConfig])
        .map(([platform, handle]) => ({
            platform,
            url: socialMediaConfig[platform as keyof typeof socialMediaConfig].baseUrl + handle,
            Icon: socialMediaConfig[platform as keyof typeof socialMediaConfig].icon,
        }));

    const where = areaSuffix(organizer);
    const featuredCity = featured ? getVenue(featured).city : undefined;
    const featuredWhere = featuredCity ? ` · ${featuredCity}` : '';
    const serviceList = (content.services || []).slice(0, 3).map(service => service.title.toLowerCase()).join(', ');
    const serviceNames = serviceList.charAt(0).toUpperCase() + serviceList.slice(1);
    const seo: Record<OrganizerSitePage, { title: string; description: string }> = {
        home: {
            title: organizer.settings?.seo_title || t`${organizer.name} — events and concerts${where} | Official ticketing`,
            description: organizer.settings?.seo_description || (featured
                ? t`${content.tagline || organizer.name}. Next event: ${featured.title}, ${longDate(featured)}${featuredWhere}. Book your tickets online: instant e-ticket, secure payment.`
                : t`${content.tagline || organizer.name}. Discover the upcoming events and book your tickets online.`),
        },
        'a-propos': {
            title: t`${organizer.name}: story, vision and team${where}`,
            description: content.tagline || stripHtml(content.story).slice(0, 155) || t`Discover the story, the vision and the team of ${organizer.name}.`,
        },
        evenements: {
            title: t`Events and concerts${where}: line-up and archives | ${organizer.name}`,
            description: t`All upcoming events by ${organizer.name}${where}, official ticketing and a look back at past editions.`,
        },
        services: {
            title: serviceNames
                ? t`${serviceNames}${where} | ${organizer.name}`
                : t`Services${where} | ${organizer.name}`,
            description: content.services_intro || t`${organizer.name} supports companies, institutions and partners in creating their events${where}.`,
        },
        partenaires: {
            title: t`Partners and sponsorship | ${organizer.name}`,
            description: content.partners_intro || t`Discover the partners of ${organizer.name} and how to associate your brand with our events.`,
        },
        contact: {
            title: t`Contact and press | ${organizer.name}`,
            description: t`Contact ${organizer.name}${where}: questions, bookings, partnerships and press requests.`,
        },
    };

    const socialUrls = socialLinks.map(link => link.url);
    const organizationJsonLd = {
        '@type': 'Organization',
        '@id': `${origin}/#organization`,
        name: organizer.name,
        url: pageUrl('home'),
        logo: logo?.url,
        description: content.tagline || undefined,
        areaServed: content.area || undefined,
        sameAs: socialUrls.length > 0 ? socialUrls : undefined,
        contactPoint: content.press_email || content.press_phone ? {
            '@type': 'ContactPoint',
            contactType: 'press',
            email: content.press_email || undefined,
            telephone: content.press_phone || undefined,
        } : undefined,
    };

    const jsonLdGraph: object[] = [organizationJsonLd];
    if (sitePage !== 'home') {
        jsonLdGraph.push({
            '@type': 'BreadcrumbList',
            itemListElement: [
                {'@type': 'ListItem', position: 1, name: organizer.name, item: pageUrl('home')},
                {
                    '@type': 'ListItem',
                    position: 2,
                    name: navItems.find(item => item.page === sitePage)?.label || seo[sitePage].title,
                    item: canonicalUrl,
                },
            ],
        });
    }
    if ((sitePage === 'home' || sitePage === 'evenements') && !isPastEvents) {
        jsonLdGraph.push(...events.map(event => buildEventJsonLd(event, eventHomepageUrl(event), organizer.name)));
    }
    if (sitePage === 'services') {
        jsonLdGraph.push(...(content.services || []).map(service => ({
            '@type': 'Service',
            name: service.title,
            description: service.text || undefined,
            provider: {'@id': `${origin}/#organization`},
            areaServed: content.area || undefined,
        })));
    }
    if (sitePage === 'home') {
        jsonLdGraph.push({
            '@type': 'FAQPage',
            mainEntity: getFaq().map(item => ({
                '@type': 'Question',
                name: item.question,
                acceptedAnswer: {'@type': 'Answer', text: item.answer},
            })),
        });
    }

    const pageProps: PosterPageProps = {
        organizer,
        eventsData,
        pastEventsData,
        isPastEvents,
        featured,
        tiers,
        fromPrice,
        sitePath,
        openContact,
    };

    const shareImage = (featured && getCoverUrl(featured)) || content.about_image_url || logo?.url;

    return (
        <>
            <Helmet>
                <title>{seo[sitePage].title}</title>
                <meta name="description" content={seo[sitePage].description}/>
                <link rel="canonical" href={canonicalUrl}/>
                <meta property="og:title" content={seo[sitePage].title}/>
                <meta property="og:description" content={seo[sitePage].description}/>
                <meta property="og:type" content="website"/>
                <meta property="og:url" content={canonicalUrl}/>
                <meta property="og:site_name" content={organizer.name}/>
                <meta property="og:locale" content="fr_FR"/>
                <meta name="robots" content="index, follow, max-image-preview:large"/>
                {shareImage && <meta property="og:image" content={shareImage}/>}
                <meta name="twitter:card" content="summary_large_image"/>
                <meta name="theme-color" content="#000000"/>
                <link rel="preconnect" href="https://fonts.googleapis.com"/>
                <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin=""/>
                <link
                    rel="stylesheet"
                    href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@500;600;700;800;900&family=Inter:wght@400;500;600&display=swap"
                />
                <script type="application/ld+json">
                    {JSON.stringify({'@context': 'https://schema.org', '@graph': jsonLdGraph})}
                </script>
            </Helmet>
            <style>{`body, .ssr-loader { background-color: #000 !important; }`}</style>

            <div className={classes.page} style={{'--poster-accent': accent} as React.CSSProperties}>
                <a href="#main" className={classes.skipLink}>{t`Skip to content`}</a>

                <header className={classes.nav}>
                    <div className={classes.navInner}>
                        <Link to={sitePath('home')} className={classes.navBrand} aria-label={t`${organizer.name} — home`}>
                            {logo
                                ? <img src={logo.url} alt={organizer.name} className={classes.navLogo}/>
                                : <span className={classes.wordmark}>{organizer.name}</span>}
                        </Link>
                        <nav className={classes.navLinks} aria-label={t`Main menu`}>
                            {navItems.filter(item => item.page !== 'home').map(item => (
                                <Link
                                    key={item.page}
                                    to={sitePath(item.page)}
                                    aria-current={sitePage === item.page ? 'page' : undefined}
                                >
                                    {item.label}
                                </Link>
                            ))}
                        </nav>
                        {featured && !allSoldOut ? (
                            <Link to={eventHomepagePath(featured)} className={classes.navCta}>{t`Book`}</Link>
                        ) : (
                            <button className={classes.navCta} onClick={openContact}>{t`Contact`}</button>
                        )}
                        <button
                            className={classes.menuToggle}
                            onClick={() => setMenuOpen(open => !open)}
                            aria-expanded={menuOpen}
                            aria-controls="mobile-menu"
                            aria-label={menuOpen ? t`Close menu` : t`Open menu`}
                        >
                            {menuOpen ? <IconX size={22}/> : <IconMenu2 size={22}/>}
                        </button>
                    </div>
                    {menuOpen && (
                        <nav id="mobile-menu" className={classes.mobileMenu} aria-label={t`Main menu`}>
                            {navItems.map(item => (
                                <Link
                                    key={item.page}
                                    to={sitePath(item.page)}
                                    aria-current={sitePage === item.page ? 'page' : undefined}
                                >
                                    {item.label}
                                </Link>
                            ))}
                        </nav>
                    )}
                </header>

                <main id="main">
                    {sitePage === 'home' && <HomePage {...pageProps}/>}
                    {sitePage === 'a-propos' && <UniversePage {...pageProps}/>}
                    {sitePage === 'services' && <ServicesPage {...pageProps}/>}
                    {sitePage === 'evenements' && <EventsPage {...pageProps}/>}
                    {sitePage === 'partenaires' && <PartnersPage {...pageProps}/>}
                    {sitePage === 'contact' && <ContactPage {...pageProps}/>}
                </main>

                <footer className={classes.footer}>
                    <div className={classes.footerInner}>
                        <div className={classes.footerBrand}>
                            {logo && <img src={logo.url} alt={organizer.name} className={classes.footerLogo} loading="lazy"/>}
                            {content.tagline && <p>{content.tagline}</p>}
                        </div>
                        <nav className={classes.footerNav} aria-label={t`Site map`}>
                            {navItems.map(item => <Link key={item.page} to={sitePath(item.page)}>{item.label}</Link>)}
                        </nav>
                        <div className={classes.footerSide}>
                            {socialLinks.length > 0 && (
                                <div className={classes.socials}>
                                    {socialLinks.map(({platform, url, Icon}) => (
                                        <a key={platform} href={url} target="_blank" rel="noopener noreferrer" aria-label={platform}>
                                            <Icon size={20}/>
                                        </a>
                                    ))}
                                </div>
                            )}
                            <button className={classes.footerContact} onClick={openContact}>
                                <IconMail size={16}/> {t`Contact the organizer`}
                            </button>
                        </div>
                    </div>
                    <PoweredByFooter className={classes.poweredBy}/>
                </footer>

                {featured && !allSoldOut && (
                    <div className={classes.stickyBar}>
                        <div className={classes.stickyText}>
                            <strong>{featured.title}</strong>
                            {fromPrice && <span>{fromPrice}</span>}
                        </div>
                        <Link to={eventHomepagePath(featured)} className={classes.stickyCta}>{t`Book`}</Link>
                    </div>
                )}
            </div>

            <ContactOrganizerModal
                opened={contactModalOpen}
                onClose={() => setContactModalOpen(false)}
                organizer={organizer}
            />
            {consentPending && <CookieConsentBanner onConsent={onConsent}/>}
        </>
    );
};

export default PosterTemplate;

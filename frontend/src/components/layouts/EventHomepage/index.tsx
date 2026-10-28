import classes from "./EventHomepage.module.scss";
import SelectProducts from "../../routes/product-widget/SelectProducts";
import "../../../styles/widget/default.scss";
import React, {useEffect, useRef, useState} from "react";
import {EventDocumentHead} from "../../common/EventDocumentHead";
import {eventCoverImage, eventHomepageUrl, imageUrl, organizerHomepageUrl} from "../../../utilites/urlHelper.ts";
import {Event, IdParam, OrganizerStatus} from "../../../types.ts";
import {EventNotAvailable} from "./EventNotAvailable";
import {
    IconCalendar,
    IconCalendarOff,
    IconCalendarPlus,
    IconExternalLink,
    IconMail,
    IconMapPin,
    IconShare,
    IconTicket,
    IconWorld
} from "@tabler/icons-react";
import {Anchor} from "@mantine/core";
import {t} from "@lingui/macro";
import {PoweredByFooter} from "../../common/PoweredByFooter";
import {ContactOrganizerModal} from "../../common/ContactOrganizerModal";
import {PublicOverlayTheme} from "../../common/PublicOverlayTheme";
import {socialMediaConfig} from "../../../constants/socialMediaConfig";
import {
    formatAddress,
    getGoogleMapsUrl,
    getShortLocationDisplay,
    isAddressSet
} from "../../../utilites/addressUtilities.ts";
import {StatusToggle} from "../../common/StatusToggle";
import {computeThemeVariables, validateThemeSettings} from "../../../utilites/themeUtils.ts";
import {useOrganizerTrackingPixels} from "../../../hooks/useOrganizerTrackingPixels";
import {trackPixelEvent, hasActivePixels} from "../../../utilites/trackingPixels";
import {openConsentPreferences} from "../../../utilites/trackingPixels/consent.ts";
import {
    getPageViewEventId,
    isMetaServerEventsEnabled,
    newMetaEventId,
    sendMetaServerEvent,
} from "../../../utilites/trackingPixels/metaServerEvents.ts";
import {ensureHomepageFontLoaded, ORGANIZER_FONT_ATTRIBUTE} from "../../../utilites/fontLoader.ts";
import {isOrganizerFontActive} from "../../../constants/homepageFonts.ts";
import {ShareComponent} from "../../common/ShareIcon";
import {EventDateRange} from "../../common/EventDateRange";
import {CalendarOptionsPopover} from "../../common/CalendarOptionsPopover";
import {isDateInPast} from "../../../utilites/dates.ts";
import {hasVisibleHtmlContent} from "../../../utilites/helpers.ts";
import {RichDescription} from "../../common/RichDescription";
import {SsrLoaderStyle} from "../../common/SsrLoaderStyle";
import {organizerLogoUrl} from "../../../utilites/organizerLogo.ts";
import {ssrLoaderBackground} from "../../../utilites/ssrLoader.ts";
import {useCoverVideoSource} from "../../../hooks/useCoverVideoSource.ts";
import {EmbeddedRegistration} from "../../routes/product-widget/EmbeddedRegistration";
import {getEventProducts, isEmbeddedRegistrationEnabled} from "../../../utilites/embeddedRegistration.ts";
import {RichFooter} from "./RichFooter";
import {EventFactSummary} from "../../common/EventFactSummary";
import {useRegisteredOnDevice} from "../../../hooks/useRegisteredOnDevice.ts";
import {AlreadyRegisteredLink, AttendanceAccessModal, RegisteredNotice} from "./AttendanceAccess";
import {alignTicketsOnArrival, scrollToTicketsSection} from "../../../utilites/ticketsAnchor.ts";
import {useGetLiveChatConfig} from "../../../queries/useGetLiveChatConfig.ts";
import {Helmet} from "react-helmet-async";

const VIDEO_PAUSE_PROGRESS = 0.6;
// React 18 has no fetchPriority prop: passed as a plain attribute.
const HIGH_FETCH_PRIORITY = {fetchpriority: 'high'} as Record<string, string>;

interface EventHomepageProps {
    event?: Event;
    promoCodeValid?: boolean;
    promoCode?: string;
}

const EventHomepage = ({...loaderData}: EventHomepageProps) => {
    const {event, promoCodeValid, promoCode} = loaderData;
    const [showScrollButton, setShowScrollButton] = useState(false);
    const [contactModalOpen, setContactModalOpen] = useState(false);
    const [contactModalMounted, setContactModalMounted] = useState(false);
    const ticketsSectionRef = useRef<HTMLElement>(null);
    const fullscreenHeroRef = useRef<HTMLElement>(null);
    const [attendanceAccessOpen, setAttendanceAccessOpen] = useState(false);
    const [attendanceAccessMounted, setAttendanceAccessMounted] = useState(false);
    const [registerAnotherPerson, setRegisterAnotherPerson] = useState(false);
    const {attendanceEnabled, registered: registeredOnDevice, forget: forgetRegistration} = useRegisteredOnDevice(event);
    const openAttendanceAccess = () => {
        setAttendanceAccessMounted(true);
        setAttendanceAccessOpen(true);
    };
    const openContactModal = () => {
        setContactModalMounted(true);
        setContactModalOpen(true);
    };

    useGetLiveChatConfig(event?.organizer?.id);

    useEffect(() => alignTicketsOnArrival(), []);

    const {consentGranted} = useOrganizerTrackingPixels(
        event?.organizer?.settings?.tracking_pixels
    );

    const viewContentEventId = useRef<{ eventId?: IdParam; id: string } | null>(null);

    useEffect(() => {
        if (!event?.id) return;
        if (viewContentEventId.current?.eventId !== event.id) {
            viewContentEventId.current = {eventId: event.id, id: newMetaEventId('vc')};
        }
        const metaEventId = viewContentEventId.current.id;

        if (consentGranted && hasActivePixels()) {
            trackPixelEvent({
                eventName: 'ViewContent',
                contentName: event.title,
                contentId: event.id,
                eventId: metaEventId,
            });
        }

        if (isMetaServerEventsEnabled(event.organizer?.settings, consentGranted)) {
            sendMetaServerEvent(event.id, 'PageView', getPageViewEventId());
            sendMetaServerEvent(event.id, 'ViewContent', metaEventId);
        }
    }, [event?.id, consentGranted]);

    useEffect(() => {
        let showTimer: NodeJS.Timeout;

        const checkTicketsPosition = () => {
            if (ticketsSectionRef.current) {
                const rect = ticketsSectionRef.current.getBoundingClientRect();
                const isBelowFold = rect.top > window.innerHeight;
                const isAboveView = rect.bottom < 0;
                const heroCoversScreen = !!fullscreenHeroRef.current && window.scrollY < window.innerHeight * 0.6;
                const shouldShowButton = (isBelowFold || isAboveView) && !heroCoversScreen;
                setShowScrollButton(shouldShowButton);
            }
        };

        showTimer = setTimeout(() => {
            checkTicketsPosition();
        }, 500);

        const handleScroll = () => {
            checkTicketsPosition();
        };

        const handleResize = () => {
            checkTicketsPosition();
        };

        window.addEventListener('scroll', handleScroll);
        window.addEventListener('resize', handleResize);

        return () => {
            clearTimeout(showTimer);
            window.removeEventListener('scroll', handleScroll);
            window.removeEventListener('resize', handleResize);
        };
    }, []);

    useEffect(() => {
        let frame = 0;

        const updateHeroProgress = () => {
            frame = 0;
            const hero = fullscreenHeroRef.current;
            if (!hero) {
                return;
            }
            const progress = Math.min(1, Math.max(0, window.scrollY / Math.max(1, window.innerHeight)));
            hero.style.setProperty('--hero-progress', progress.toFixed(3));

            const video = hero.querySelector('video');
            if (video && hero.dataset.pauseOnScroll === 'true') {
                if (progress >= VIDEO_PAUSE_PROGRESS && !video.paused) {
                    video.pause();
                } else if (progress < VIDEO_PAUSE_PROGRESS && video.paused) {
                    video.play().catch(() => undefined);
                }
            }
        };

        const handleScroll = () => {
            if (!frame) {
                frame = window.requestAnimationFrame(updateHeroProgress);
            }
        };

        updateHeroProgress();
        window.addEventListener('scroll', handleScroll, {passive: true});
        window.addEventListener('resize', handleScroll);

        return () => {
            if (frame) {
                window.cancelAnimationFrame(frame);
            }
            window.removeEventListener('scroll', handleScroll);
            window.removeEventListener('resize', handleScroll);
        };
    }, []);

    const scrollToTickets = () => {
        scrollToTicketsSection(ticketsSectionRef.current);
    };

    if (!event) {
        return <EventNotAvailable/>;
    }

    const rawThemeSettings = event?.settings?.homepage_theme_settings;
    const themeSettings = validateThemeSettings(rawThemeSettings);
    const organizerFont = event.organizer?.custom_font;
    const cssVars = computeThemeVariables(themeSettings, organizerFont);
    const backgroundType = themeSettings.background_type;
    const organizerFontActive = isOrganizerFontActive(themeSettings.font_family, organizerFont);

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

    const overlayTheme = {
        accentColor: themeSettings.accent,
        mode: themeSettings.mode,
        fontFamily: cssVars['--theme-font-family'],
    };

    const coverImageData = eventCoverImage(event);
    const coverImage = coverImageData?.url;
    const coverVideo = event?.images?.find((image) => image.type === 'EVENT_COVER_VIDEO')?.url;
    const coverVideoMobile = event?.images?.find((image) => image.type === 'EVENT_COVER_VIDEO_MOBILE')?.url;
    const coverVideoSource = useCoverVideoSource(coverVideo, coverVideoMobile);
    const flyer = event?.images?.find((image) => image.type === 'EVENT_FLYER')?.url;
    const heroPartnerLogo = event?.images?.find((image) => image.type === 'EVENT_HERO_PARTNER_LOGO')?.url;
    const heroSignatureLogo = event?.images?.find((image) => image.type === 'EVENT_HERO_SIGNATURE_LOGO')?.url;
    const heroBrandLogo = event?.images?.find((image) => image.type === 'EVENT_HERO_BRAND_LOGO')?.url;
    const organizer = event.organizer!;
    const organizerSocials = organizer?.settings?.social_media_handles;
    const organizerLogo = organizerLogoUrl(organizer?.images, cssVars['--theme-surface'])
        ?? imageUrl('ORGANIZER_LOGO', organizer?.images);
    const heroOrganizerLogo = organizerLogoUrl(organizer?.images, 'dark');
    const footerOrganizerLogo = organizerLogoUrl(organizer?.images, themeSettings.accent);
    const loaderOrganizerLogo = organizerLogoUrl(organizer?.images, ssrLoaderBackground({
        background: themeSettings.background,
        accent: themeSettings.accent,
        mode: themeSettings.mode,
        backgroundType,
    }));
    const organizerLocation = organizer?.settings?.location_details;
    const websiteUrl = organizer?.website;
    const locationDetails = event.settings?.location_details;
    const isOnlineEvent = event.settings?.is_online_event;
    const hasLocation = isAddressSet(locationDetails) && !isOnlineEvent;

    const socialLinks = organizerSocials ? Object.entries(organizerSocials)
        .filter(([platform, handle]) => handle && socialMediaConfig[platform as keyof typeof socialMediaConfig])
        .map(([platform, handle]) => ({
            platform,
            handle: handle as string,
            config: socialMediaConfig[platform as keyof typeof socialMediaConfig]
        })) : [];

    const getStatusBadge = () => {
        const products = event.products || event.product_categories?.flatMap(c => c.products || []) || [];

        if (products.length === 0) {
            return null;
        }

        const availableProducts = products.filter(p => p.is_available && !p.is_sold_out);
        const allSoldOut = products.every(p => p.is_sold_out);

        if (allSoldOut) {
            return {text: t`Sold Out`, variant: 'danger'};
        }

        if (availableProducts.length === 0) {
            return null;
        }

        return {text: t`Tickets Available`, variant: 'success'};
    };

    const statusBadge = getStatusBadge();

    const isFullscreenHero = themeSettings.hero_layout === 'FULLSCREEN' && !!(coverVideo || coverImage);
    const isFreeEvent = (event.products || event.product_categories?.flatMap(c => c.products || []) || [])
        .every(p => !p.prices?.some(price => Number(price.price) > 0));

    const isEmbeddedRegistration = isFullscreenHero && isEmbeddedRegistrationEnabled(event);
    const isEmbeddedCardRegistration = !isFullscreenHero && isEmbeddedRegistrationEnabled(event);
    const isWaitingForSaleStart = getEventProducts(event).some(product => product.is_before_sale_start_date)
        && !getEventProducts(event).some(product => product.is_available && !product.is_before_sale_start_date);

    const renderTicketsSection = () => (
        <div className={`${classes.section} ${classes.ticketsSection}`}
             ref={ticketsSectionRef as React.RefObject<HTMLDivElement>}
             id="tickets">
            <SelectProducts
                colors={{
                    background: "transparent",
                    primary: "var(--event-primary-color)",
                    primaryText: "var(--event-primary-text-color)",
                    secondary: "var(--event-primary-color)",
                    secondaryText: "var(--event-accent-contrast)",
                    bodyBackground: "var(--event-bg-color)",
                }}
                continueButtonText={event.settings?.continue_button_text}
                padding={"0px"}
                event={event}
                promoCodeValid={promoCodeValid}
                promoCode={promoCode}
                showPoweredBy={false}
            />
        </div>
    );

    const mapUrl = event.settings?.maps_url || (locationDetails ? getGoogleMapsUrl(locationDetails) : null);

    return (
        <>
            {event?.status && event?.id && (
                <StatusToggle
                    entityType="event"
                    entityId={event.id}
                    currentStatus={event.status as 'DRAFT' | 'LIVE'}
                    entityName={event.title}
                    onSuccess={() =>
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000)}
                />
            )}

            <main
                className={classes.pageWrapper}
                style={themeStyles}
                data-mode={themeSettings.mode}
                {...(organizerFontActive ? {[ORGANIZER_FONT_ATTRIBUTE]: ''} : {})}
            >
                <SsrLoaderStyle
                    background={themeSettings.background}
                    accent={themeSettings.accent}
                    mode={themeSettings.mode}
                    backgroundType={backgroundType}
                    logoUrl={loaderOrganizerLogo}
                />

                {event && <EventDocumentHead event={event}/>}
                {coverImage && (
                    <Helmet>
                        <link rel="preload" as="image" href={coverImage} {...HIGH_FETCH_PRIORITY}/>
                    </Helmet>
                )}

                {attendanceEnabled && attendanceAccessMounted && (
                    <AttendanceAccessModal
                        opened={attendanceAccessOpen}
                        onClose={() => setAttendanceAccessOpen(false)}
                        eventId={event.id}
                        themeStyle={themeStyles}
                        onNoActiveRegistration={forgetRegistration}
                    />
                )}

                {/* Background */}
                {(coverImage && backgroundType === 'MIRROR_COVER_IMAGE') ? (
                    <div
                        className={classes.background}
                        style={{backgroundImage: `url(${coverImage})`}}
                    />
                ) : (
                    <div
                        className={classes.background}
                        style={{backgroundColor: 'var(--event-bg-color)'}}
                    />
                )}
                <div
                    className={classes.backgroundOverlay}
                    style={backgroundType === 'MIRROR_COVER_IMAGE' ? {
                        '--overlay-color': themeSettings.background
                    } as React.CSSProperties : undefined}
                />

                <div className={classes.container}>
                    <EventFactSummary event={event} eventUrl={eventHomepageUrl(event)}/>
                    {isFullscreenHero && (
                        <section
                            className={classes.fullscreenHero}
                            ref={fullscreenHeroRef}
                            data-pause-on-scroll={isEmbeddedRegistration ? 'true' : undefined}
                            data-sale-countdown={isEmbeddedRegistration && isWaitingForSaleStart ? 'true' : undefined}
                        >
                            {coverVideoSource ? (
                                <video
                                    src={coverVideoSource}
                                    poster={coverImage}
                                    className={classes.fullscreenMedia}
                                    aria-hidden="true"
                                    autoPlay
                                    muted
                                    loop
                                    playsInline
                                    preload="metadata"
                                />
                            ) : coverImage ? (
                                <img src={coverImage} alt="" aria-hidden="true" className={classes.fullscreenMedia}
                                     {...HIGH_FETCH_PRIORITY}/>
                            ) : null}
                            <div className={classes.fullscreenVeil}/>
                            <div className={classes.fullscreenTopBar}>
                                {heroPartnerLogo ? (
                                    <img src={heroPartnerLogo} alt="" className={classes.fullscreenPartnerLogo}/>
                                ) : <span/>}
                                {heroBrandLogo ? (
                                    <img src={heroBrandLogo} alt={organizer.name} className={classes.fullscreenBrandCustom}/>
                                ) : heroOrganizerLogo && (
                                    <img src={heroOrganizerLogo} alt={organizer.name} className={classes.fullscreenBrand}/>
                                )}
                            </div>
                            <div className={classes.fullscreenCenter}>
                                <div className={classes.fullscreenLockup}>
                                    <h1 className={classes.fullscreenTitle}>
                                        {(themeSettings.hero_title?.trim() || event.title)
                                            .split('\n')
                                            .map((line, index) => <span key={index}>{line}</span>)}
                                    </h1>
                                    {themeSettings.hero_subtitle?.trim() && (
                                        <div className={classes.fullscreenSubtitle}>{themeSettings.hero_subtitle}</div>
                                    )}
                                    <div className={classes.fullscreenDate}>
                                        <EventDateRange event={event}/>
                                    </div>
                                    {(themeSettings.hero_tagline?.trim() || heroSignatureLogo) && (
                                        <div className={classes.fullscreenTagline}>
                                            {themeSettings.hero_tagline?.trim() && <span>{themeSettings.hero_tagline}</span>}
                                            {heroSignatureLogo && (
                                                <img src={heroSignatureLogo} alt="" className={classes.fullscreenSignatureLogo}/>
                                            )}
                                        </div>
                                    )}
                                </div>
                            </div>
                            <div className={classes.fullscreenFooter}>
                                {attendanceEnabled && !registeredOnDevice && (
                                    <AlreadyRegisteredLink className={classes.fullscreenAlreadyLink} onClick={openAttendanceAccess}/>
                                )}
                                {registeredOnDevice ? (
                                    <button type="button" className={classes.fullscreenCta} onClick={openAttendanceAccess}>
                                        <span className={classes.fullscreenCtaArrow} aria-hidden="true">→</span>
                                        {t`Confirm or cancel my attendance`}
                                    </button>
                                ) : (
                                    <button type="button" className={classes.fullscreenCta} onClick={scrollToTickets}>
                                        <span className={classes.fullscreenCtaArrow} aria-hidden="true">→</span>
                                        {isFreeEvent ? t`Register for free` : t`Get Tickets`}
                                    </button>
                                )}
                            </div>
                        </section>
                    )}
                    {isEmbeddedRegistration && registeredOnDevice && !registerAnotherPerson && (
                        <RegisteredNotice
                            sectionRef={ticketsSectionRef}
                            onManageAttendance={openAttendanceAccess}
                            onRegisterAnother={() => setRegisterAnotherPerson(true)}
                        />
                    )}
                    {isEmbeddedRegistration && (!registeredOnDevice || registerAnotherPerson) && (
                        <EmbeddedRegistration
                            onAlreadyRegistered={attendanceEnabled ? openAttendanceAccess : undefined}
                            event={event}
                            promoCode={promoCode}
                            promoCodeValid={promoCodeValid}
                            sectionRef={ticketsSectionRef}
                            renderFallback={() => (
                                <div className={classes.wrapper}>
                                    <div className={classes.mainCard}>
                                        {renderTicketsSection()}
                                    </div>
                                </div>
                            )}
                        />
                    )}
                    {isEmbeddedRegistration && event.settings?.rich_footer_enabled ? (
                        <RichFooter
                            event={event}
                            organizer={organizer}
                            organizerLogo={footerOrganizerLogo}
                            socialLinks={socialLinks}
                            websiteUrl={websiteUrl}
                            onRegister={scrollToTickets}
                            onContact={openContactModal}
                            onManageCookies={openConsentPreferences}
                        />
                    ) : isEmbeddedRegistration ? (
                        <footer className={classes.landingFooter}>
                            <div className={classes.landingFooterInner}>
                                {organizer && (
                                    <div className={classes.landingOrganizer}>
                                        <h2 className={classes.landingOrganizerName}>{organizer.name}</h2>
                                        {organizer.description && (
                                            <RichDescription className={classes.landingOrganizerBio} html={organizer.description}/>
                                        )}
                                        <button
                                            type="button"
                                            onClick={openContactModal}
                                            className={classes.landingContact}
                                        >
                                            <IconMail/>
                                            {t`Contact`}
                                        </button>
                                    </div>
                                )}
                                <PoweredByFooter className={classes.landingPoweredBy}/>
                            </div>
                        </footer>
                    ) : (
                        <div className={classes.wrapper}>
                            {/* Main unified card */}
                            <div className={classes.mainCard}>
                                {/* Hero Section */}
                                <div className={classes.heroSection}>
                                    {(coverImage || coverVideo) && !isFullscreenHero && (
                                        <div
                                            className={classes.coverWrapper}
                                            style={coverVideo ? {
                                                '--cover-aspect-ratio': '16 / 9',
                                            } as React.CSSProperties : (coverImageData?.width && coverImageData?.height) ? {
                                                '--cover-aspect-ratio': `${coverImageData.width} / ${coverImageData.height}`,
                                            } as React.CSSProperties : undefined}
                                        >
                                            {coverImageData?.lqip_base64 && (
                                                <img
                                                    src={coverImageData.lqip_base64}
                                                    alt=""
                                                    aria-hidden="true"
                                                    className={classes.coverLqip}
                                                />
                                            )}
                                            {coverVideoSource ? (
                                                <video
                                                    src={coverVideoSource}
                                                    poster={coverImage}
                                                    className={classes.coverImage}
                                                    aria-label={event.title}
                                                    autoPlay
                                                    muted
                                                    loop
                                                    playsInline
                                                    preload="metadata"
                                                />
                                            ) : coverImage ? (
                                                <img
                                                    src={coverImage}
                                                    alt={event.title}
                                                    className={classes.coverImage}
                                                    {...HIGH_FETCH_PRIORITY}
                                                />
                                            ) : null}
                                            <div className={classes.heroGradient}/>
                                            {statusBadge && (
                                                <div className={classes.statusBadges}>
                                                    <span className={classes.statusBadge}>
                                                        <IconTicket/>
                                                        {statusBadge.text}
                                                    </span>
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    {/* Event Header */}
                                    <div className={classes.eventHeader}>
                                        <div className={classes.headerTopRow}>
                                            {organizer && organizer.status === OrganizerStatus.LIVE ? (
                                                <a
                                                    href={organizerHomepageUrl(organizer)}
                                                    className={classes.organizerPill}
                                                >
                                                    {organizerLogo ? (
                                                        <img
                                                            src={organizerLogo}
                                                            alt={organizer.name}
                                                            className={classes.organizerPillAvatar}
                                                        />
                                                    ) : (
                                                        <span className={classes.organizerPillAvatarPlaceholder}>
                                                            {organizer.name.charAt(0).toUpperCase()}
                                                        </span>
                                                    )}
                                                    <span className={classes.organizerPillName}>
                                                        {organizer.name}
                                                    </span>
                                                </a>
                                            ) : (
                                                <div className={classes.organizerPill}>
                                                    {organizerLogo ? (
                                                        <img
                                                            src={organizerLogo}
                                                            alt={organizer?.name || ''}
                                                            className={classes.organizerPillAvatar}
                                                        />
                                                    ) : (
                                                        <span className={classes.organizerPillAvatarPlaceholder}>
                                                            {organizer?.name?.charAt(0).toUpperCase() || '?'}
                                                        </span>
                                                    )}
                                                    <span className={classes.organizerPillName}>
                                                        {organizer?.name}
                                                    </span>
                                                </div>
                                            )}

                                            <div className={classes.actionButtons}>
                                                <PublicOverlayTheme {...overlayTheme}>
                                                    <ShareComponent
                                                        title={'Check out this event: ' + event.title}
                                                        text={'Check out this event: ' + event.title}
                                                        url={eventHomepageUrl(event)}
                                                        imageUrl={coverImage || undefined}
                                                    >
                                                        <button className={classes.actionButton} title={t`Share`}>
                                                            <IconShare/>
                                                        </button>
                                                    </ShareComponent>
                                                </PublicOverlayTheme>
                                                {/* Future enhancement: Favorite/Heart button */}
                                                {/* <button className={`${classes.actionButton} ${classes.favoriteButton}`} title={t`Save`}>
                                                    <IconHeart />
                                                </button> */}
                                            </div>
                                        </div>

                                        {isFullscreenHero ? (
                                            <h2 className={classes.eventTitle}>{event.title}</h2>
                                        ) : (
                                            <h1 className={classes.eventTitle}>{event.title}</h1>
                                        )}

                                        <div className={classes.eventMeta}>
                                            {/* Date/Time */}
                                            <div className={classes.metaItem}>
                                                <div className={classes.metaIconBox}>
                                                    <IconCalendar/>
                                                </div>
                                                <div className={classes.metaContent}>
                                                    <div className={classes.metaPrimary}>
                                                        <EventDateRange event={event}/>
                                                    </div>
                                                </div>
                                                <CalendarOptionsPopover event={event}>
                                                    <button className={classes.addToCalendarButton}>
                                                        <IconCalendarPlus/>
                                                        {t`Add to Calendar`}
                                                    </button>
                                                </CalendarOptionsPopover>
                                            </div>

                                            {/* Event Ended */}
                                            {event.end_date && isDateInPast(event.end_date) && (
                                                <div className={classes.metaItem}>
                                                    <div className={classes.metaIconBox}>
                                                        <IconCalendarOff/>
                                                    </div>
                                                    <div className={classes.metaContent}>
                                                        <div className={classes.metaPrimary}>{t`This event has ended`}</div>
                                                    </div>
                                                </div>
                                            )}

                                            {/* Online Event */}
                                            {isOnlineEvent && (
                                                <div className={classes.metaItem}>
                                                    <div className={classes.metaIconBox}>
                                                        <IconWorld/>
                                                    </div>
                                                    <div className={classes.metaContent}>
                                                        <div className={classes.metaPrimary}>{t`Online Event`}</div>
                                                        <div className={classes.metaSecondary}>
                                                            {t`Join from anywhere`}
                                                        </div>
                                                    </div>
                                                </div>
                                            )}

                                            {/* Location */}
                                            {hasLocation && locationDetails && (
                                                <div className={classes.metaItem}>
                                                    <div className={classes.metaIconBox}>
                                                        <IconMapPin/>
                                                    </div>
                                                    <div className={classes.metaContent}>
                                                        <div className={classes.metaPrimary}>
                                                            {locationDetails.venue_name || locationDetails.city}
                                                        </div>
                                                        {locationDetails.venue_name && locationDetails.city && (
                                                            <div className={classes.metaSecondary}>
                                                                {locationDetails.city}
                                                            </div>
                                                        )}
                                                        {mapUrl && (
                                                            <a
                                                                href={mapUrl}
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                className={classes.metaLink}
                                                                title={formatAddress(locationDetails)}
                                                            >
                                                                {t`Get Directions`}
                                                                <IconExternalLink/>
                                                            </a>
                                                        )}
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                {/* About Section */}
                                {hasVisibleHtmlContent(event?.description) && (
                                    <div className={classes.section}>
                                        <div className={classes.sectionHeader}>
                                            <h2 className={classes.sectionTitle}>{t`About`}</h2>
                                        </div>
                                        <RichDescription className={classes.description} html={event.description as string}/>
                                    </div>
                                )}

                                {isEmbeddedCardRegistration ? (
                                    <EmbeddedRegistration
                                        variant="card"
                                        className={`${classes.section} ${classes.embeddedSection}`}
                                        event={event}
                                        promoCode={promoCode}
                                        promoCodeValid={promoCodeValid}
                                        sectionRef={ticketsSectionRef}
                                        renderFallback={renderTicketsSection}
                                    />
                                ) : renderTicketsSection()}

                                {coverVideo && flyer && (
                                    <div className={`${classes.section} ${classes.flyerSection}`} id="flyer">
                                        <a href={flyer} target="_blank" rel="noopener noreferrer" className={classes.flyerLink}
                                           aria-label={t`Open the event flyer`}>
                                            <img src={flyer} alt={t`Flyer: ${event.title}`} loading="lazy"
                                                 className={classes.flyerImage}/>
                                        </a>
                                    </div>
                                )}

                                {/* Organizer Section */}
                                {organizer && organizer.status === OrganizerStatus.LIVE && (
                                    <div className={classes.section} id="organizer">
                                        <div className={classes.sectionHeader}>
                                            <h2 className={classes.sectionTitle}>{t`Organizer`}</h2>
                                        </div>
                                        <div className={classes.organizerCard}>
                                            {organizerLogo ? (
                                                <img
                                                    src={organizerLogo}
                                                    alt={organizer.name}
                                                    className={classes.organizerAvatar}
                                                    loading="lazy"
                                                />
                                            ) : (
                                                <div className={classes.organizerAvatarPlaceholder}>
                                                    {organizer.name.charAt(0).toUpperCase()}
                                                </div>
                                            )}
                                            <div className={classes.organizerContent}>
                                                <div className={classes.organizerHeader}>
                                                    <div>
                                                        <h3 className={classes.organizerName}>
                                                            <Anchor href={organizerHomepageUrl(organizer)}>
                                                                {organizer.name}
                                                            </Anchor>
                                                        </h3>
                                                        {getShortLocationDisplay(organizerLocation) && (
                                                            <div className={classes.organizerLocation}>
                                                                <IconMapPin/>
                                                                <a
                                                                    href={getGoogleMapsUrl(organizerLocation!)}
                                                                    target="_blank"
                                                                    rel="noopener noreferrer"
                                                                >
                                                                    {getShortLocationDisplay(organizerLocation)}
                                                                </a>
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>

                                                {organizer.description && (
                                                    <RichDescription className={classes.organizerBio} html={organizer.description}/>
                                                )}

                                                <div className={classes.organizerActions}>
                                                    {socialLinks.length > 0 && (
                                                        <div className={classes.socialLinks}>
                                                            {socialLinks.map(({platform, handle, config}) => {
                                                                const IconComponent = config.icon;
                                                                const url = config.baseUrl + handle;
                                                                return (
                                                                    <a
                                                                        key={platform}
                                                                        href={url}
                                                                        target="_blank"
                                                                        rel="noopener noreferrer"
                                                                        className={classes.socialLink}
                                                                        title={platform}
                                                                    >
                                                                        <IconComponent size={18}/>
                                                                    </a>
                                                                );
                                                            })}
                                                        </div>
                                                    )}
                                                    {websiteUrl && (() => {
                                                        try {
                                                            const hostname = new URL(websiteUrl).hostname;
                                                            return (
                                                                <a
                                                                    href={websiteUrl}
                                                                    target="_blank"
                                                                    rel="noopener noreferrer"
                                                                    className={classes.socialLink}
                                                                    title={hostname}
                                                                >
                                                                    <IconWorld size={18}/>
                                                                </a>
                                                            );
                                                        } catch {
                                                            return null;
                                                        }
                                                    })()}
                                                    <button
                                                        onClick={openContactModal}
                                                        className={classes.contactButton}
                                                    >
                                                        <IconMail/>
                                                        {t`Contact`}
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </div>

                            {/* Footer */}
                            <div className={classes.footerSection}>
                                <PoweredByFooter className={classes.poweredByFooter}/>
                            </div>
                        </div>
                    )}

                    {showScrollButton && !isEmbeddedRegistration && (
                        <button
                            type="button"
                            className={classes.scrollToTicketsButton}
                            onClick={scrollToTickets}
                        >
                            <IconTicket size={18}/>
                            {isFreeEvent ? t`Register for free` : t`Buy`}
                        </button>
                    )}

                    <PublicOverlayTheme {...overlayTheme}>
                        {contactModalMounted && (
                            <ContactOrganizerModal
                                opened={contactModalOpen}
                                onClose={() => setContactModalOpen(false)}
                                organizer={organizer}
                                eventId={event.id}
                            />
                        )}
                    </PublicOverlayTheme>
                </div>
            </main>
        </>
    );
};

export default EventHomepage;

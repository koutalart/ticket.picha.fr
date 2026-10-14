import {useEffect, useMemo, type CSSProperties} from "react";
import {useLoaderData} from "react-router";
import {Helmet} from "react-helmet-async";
import {t} from "@lingui/macro";
import {
    IconBrandFacebook,
    IconBrandInstagram,
    IconBrandLinkedin,
    IconBrandSnapchat,
    IconBrandTelegram,
    IconBrandThreads,
    IconBrandTiktok,
    IconBrandWhatsapp,
    IconBrandX,
    IconBrandYoutube,
    IconCalendarEvent,
    IconTicket,
    IconWorld,
} from "@tabler/icons-react";
import classes from "./QrLinkPage.module.scss";
import {QrLinkPageLoaderData} from "../../../routeLoaders/qrLinkPageLoader.ts";
import {QrLinkSocialLink, QrLinkSocialPlatform} from "../../../types.ts";
import {socialMediaConfig} from "../../../constants/socialMediaConfig.ts";
import {useOrganizerTrackingPixels} from "../../../hooks/useOrganizerTrackingPixels.ts";
import {CookieConsentBanner} from "../../common/CookieConsentBanner";
import {hasActivePixels, trackPixelEvent} from "../../../utilites/trackingPixels";
import {rememberQrLinkCode} from "../../../utilites/qrAttribution.ts";
import {getConfig} from "../../../utilites/config.ts";
import {getAppName} from "../../../utilites/branding.ts";

const PLATFORM_ICONS: Record<QrLinkSocialPlatform, typeof IconWorld> = {
    instagram: IconBrandInstagram,
    facebook: IconBrandFacebook,
    tiktok: IconBrandTiktok,
    whatsapp: IconBrandWhatsapp,
    snapchat: IconBrandSnapchat,
    youtube: IconBrandYoutube,
    x: IconBrandX,
    linkedin: IconBrandLinkedin,
    telegram: IconBrandTelegram,
    threads: IconBrandThreads,
    website: IconWorld,
};

const PLATFORM_LABELS: Record<QrLinkSocialPlatform, string> = {
    instagram: 'Instagram',
    facebook: 'Facebook',
    tiktok: 'TikTok',
    whatsapp: 'WhatsApp',
    snapchat: 'Snapchat',
    youtube: 'YouTube',
    x: 'X',
    linkedin: 'LinkedIn',
    telegram: 'Telegram',
    threads: 'Threads',
    website: 'Website',
};

const HANDLE_TO_PLATFORM: Partial<Record<keyof typeof socialMediaConfig, QrLinkSocialPlatform>> = {
    instagram: 'instagram',
    facebook: 'facebook',
    tiktok: 'tiktok',
    whatsapp: 'whatsapp',
    snapchat: 'snapchat',
    youtube: 'youtube',
    twitter: 'x',
    linkedin: 'linkedin',
    telegram: 'telegram',
};

const recordClick = (shortId: string, target: string) => {
    const url = `${getConfig('VITE_API_URL_CLIENT')}/public/qr-links/${shortId}/visits`;
    const body = JSON.stringify({type: 'CLICK', target});

    try {
        if (navigator.sendBeacon?.(url, new Blob([body], {type: 'application/json'}))) {
            return;
        }
    } catch {
        // Fall back to fetch below.
    }

    fetch(url, {method: 'POST', body, keepalive: true, headers: {'Content-Type': 'application/json', Accept: 'application/json'}})
        .catch(() => undefined);
};

const QrLinkPage = () => {
    const {qrLink, organizer} = useLoaderData() as QrLinkPageLoaderData;
    const {consentPending, consentGranted, onConsent} = useOrganizerTrackingPixels(organizer?.settings?.tracking_pixels);

    const socialLinks = useMemo<QrLinkSocialLink[]>(() => {
        if (qrLink?.social_links?.length) {
            return qrLink.social_links;
        }

        const handles = organizer?.settings?.social_media_handles || {};
        const fromHandles = Object.entries(handles)
            .filter(([platform, handle]) => handle && HANDLE_TO_PLATFORM[platform as keyof typeof socialMediaConfig])
            .map(([platform, handle]) => ({
                platform: HANDLE_TO_PLATFORM[platform as keyof typeof socialMediaConfig]!,
                url: socialMediaConfig[platform as keyof typeof socialMediaConfig].baseUrl + handle,
            }));

        return organizer?.settings?.website_url
            ? [...fromHandles, {platform: 'website' as const, url: organizer.settings.website_url}]
            : fromHandles;
    }, [qrLink, organizer]);

    useEffect(() => {
        rememberQrLinkCode(qrLink?.short_id);
    }, [qrLink?.short_id]);

    useEffect(() => {
        if (!qrLink || !consentGranted) return;

        const timer = setTimeout(() => {
            if (hasActivePixels()) {
                trackPixelEvent({
                    eventName: 'ViewContent',
                    contentName: qrLink.event_title || qrLink.name,
                    contentId: qrLink.event_id ?? qrLink.short_id,
                });
            }
        }, 300);

        return () => clearTimeout(timer);
    }, [qrLink?.short_id, consentGranted]);

    if (!qrLink) {
        return (
            <div className={classes.page}>
                <Helmet>
                    <title>{t`QR code unavailable`}</title>
                    <meta name="robots" content="noindex"/>
                </Helmet>
                <div className={classes.notFound}>
                    <h1 className={classes.title}>{t`This QR code is no longer active`}</h1>
                    <p className={classes.description}>{t`The organizer has paused or removed it.`}</p>
                </div>
            </div>
        );
    }

    const accent = qrLink.design?.foreground || getConfig('VITE_APP_PRIMARY_COLOR', '#40296C');
    const logo = organizer?.images?.find(image => image.type === 'ORGANIZER_LOGO');
    const title = qrLink.title || qrLink.event_title || organizer?.name || qrLink.name;
    const organizerUrl = organizer?.slug
        ? `/events/${organizer.id}/${organizer.slug}?qr=${qrLink.short_id}`
        : null;
    const ticketUrl = qrLink.ticket_url || organizerUrl;

    return (
        <div className={classes.page} style={{'--qr-accent': accent} as CSSProperties}>
            <Helmet>
                <title>{title}</title>
                <meta name="robots" content="noindex"/>
            </Helmet>
            <main className={classes.content}>
                {logo
                    ? <img className={classes.logo} src={logo.url} alt={organizer?.name}/>
                    : <div className={classes.initials} aria-hidden>{(organizer?.name || title).charAt(0)}</div>}

                <h1 className={classes.title}>{title}</h1>
                {organizer?.name && organizer.name !== title && (
                    <p className={classes.organizerName}>{organizer.name}</p>
                )}
                {qrLink.description && <p className={classes.description}>{qrLink.description}</p>}

                <nav className={classes.links}>
                    {ticketUrl && (
                        <a
                            className={classes.primaryLink}
                            href={ticketUrl}
                            onClick={() => recordClick(qrLink.short_id, qrLink.ticket_url ? 'tickets' : 'events')}
                        >
                            {qrLink.ticket_url ? <IconTicket size={20}/> : <IconCalendarEvent size={20}/>}
                            {qrLink.ticket_url ? t`Get my ticket` : t`See the events`}
                        </a>
                    )}
                    {socialLinks.map((link) => {
                        const Icon = PLATFORM_ICONS[link.platform] || IconWorld;
                        return (
                            <a
                                key={`${link.platform}-${link.url}`}
                                className={classes.socialLink}
                                href={link.url}
                                target="_blank"
                                rel="noopener noreferrer"
                                onClick={() => recordClick(qrLink.short_id, link.platform)}
                            >
                                <Icon size={20}/>
                                {link.platform === 'website' ? t`Website` : PLATFORM_LABELS[link.platform]}
                            </a>
                        );
                    })}
                </nav>

                <footer className={classes.footer}>
                    {t`Powered by`} <a href="/">{getAppName()}</a>
                </footer>
            </main>
            {consentPending && <CookieConsentBanner onConsent={onConsent}/>}
        </div>
    );
};

export default QrLinkPage;

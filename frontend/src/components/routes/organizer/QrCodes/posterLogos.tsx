import {t} from "@lingui/macro";
import {
    IconBrandFacebookFilled,
    IconBrandInstagramFilled,
    IconBrandLinkedinFilled,
    IconBrandSnapchatFilled,
    IconBrandTelegram,
    IconBrandThreads,
    IconBrandTiktokFilled,
    IconBrandWhatsappFilled,
    IconBrandXFilled,
    IconBrandYoutubeFilled,
    IconDeviceMobileFilled,
    IconMicrophoneFilled,
    IconMovie,
    IconWorld,
} from "@tabler/icons-react";
import {QrLinkDestinationType, QrPosterLogo} from "../../../../types.ts";

// Same icons (filled when available) as backend/resources/qr-poster-icons, used by the downloadable visual.
export const POSTER_LOGO_ICONS: Record<Exclude<QrPosterLogo, 'organizer'>, typeof IconWorld> = {
    website: IconWorld,
    video: IconMovie,
    podcast: IconMicrophoneFilled,
    app: IconDeviceMobileFilled,
    instagram: IconBrandInstagramFilled,
    facebook: IconBrandFacebookFilled,
    tiktok: IconBrandTiktokFilled,
    whatsapp: IconBrandWhatsappFilled,
    youtube: IconBrandYoutubeFilled,
    x: IconBrandXFilled,
    threads: IconBrandThreads,
    snapchat: IconBrandSnapchatFilled,
    linkedin: IconBrandLinkedinFilled,
    telegram: IconBrandTelegram,
};

export const posterLogoOptions = () => [
    {value: 'organizer', label: t`Organizer logo`},
    {value: 'website', label: t`Website`},
    {value: 'video', label: t`Video`},
    {value: 'podcast', label: t`Podcast`},
    {value: 'app', label: t`Mobile app`},
    {value: 'instagram', label: 'Instagram'},
    {value: 'facebook', label: 'Facebook'},
    {value: 'tiktok', label: 'TikTok'},
    {value: 'whatsapp', label: 'WhatsApp'},
    {value: 'youtube', label: 'YouTube'},
    {value: 'x', label: 'X'},
    {value: 'threads', label: 'Threads'},
    {value: 'snapchat', label: 'Snapchat'},
    {value: 'linkedin', label: 'LinkedIn'},
    {value: 'telegram', label: 'Telegram'},
];

const HOST_LOGOS: [RegExp, QrPosterLogo][] = [
    [/(^|\.)instagram\.com$/, 'instagram'],
    [/(^|\.)(facebook\.com|fb\.me|fb\.com)$/, 'facebook'],
    [/(^|\.)tiktok\.com$/, 'tiktok'],
    [/(^|\.)(whatsapp\.com|wa\.me)$/, 'whatsapp'],
    [/(^|\.)(youtube\.com|youtu\.be)$/, 'youtube'],
    [/(^|\.)(x\.com|twitter\.com)$/, 'x'],
    [/(^|\.)threads\.(net|com)$/, 'threads'],
    [/(^|\.)snapchat\.com$/, 'snapchat'],
    [/(^|\.)linkedin\.com$/, 'linkedin'],
    [/(^|\.)(t\.me|telegram\.me)$/, 'telegram'],
    [/(^|\.)(apps\.apple\.com|play\.google\.com)$/, 'app'],
];

export const suggestPosterLogo = (destinationType: QrLinkDestinationType, url: string): QrPosterLogo => {
    if (destinationType === 'APP') return 'app';
    if (destinationType !== 'URL') return 'organizer';

    try {
        const host = new URL(url).hostname.toLowerCase();
        const match = HOST_LOGOS.find(([pattern]) => pattern.test(host));
        if (match) return match[1];
        const path = new URL(url).pathname.toLowerCase();
        if (/video/.test(path)) return 'video';
        if (/audio|podcast/.test(path)) return 'podcast';
    } catch {
        // Not a valid URL yet: fall back to the website icon.
    }

    return 'website';
};

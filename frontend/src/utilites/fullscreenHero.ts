import {Event, ImageType} from "../types.ts";
import {validateThemeSettings} from "./themeUtils.ts";
import {organizerLogoUrl} from "./organizerLogo.ts";

const findImageUrl = (images: Event['images'], type: ImageType): string | undefined =>
    images?.find((image) => image.type === type)?.url;

export const getFullscreenHero = (event?: Event) => {
    const settings = validateThemeSettings(event?.settings?.homepage_theme_settings);
    const coverVideo = findImageUrl(event?.images, 'EVENT_COVER_VIDEO');
    const coverImage = findImageUrl(event?.images, 'EVENT_COVER');

    return {
        enabled: settings.hero_layout === 'FULLSCREEN' && !!(coverVideo || coverImage),
        settings,
        coverVideo,
        coverVideoMobile: findImageUrl(event?.images, 'EVENT_COVER_VIDEO_MOBILE'),
        coverImage,
        partnerLogo: findImageUrl(event?.images, 'EVENT_HERO_PARTNER_LOGO'),
        brandLogo: findImageUrl(event?.images, 'EVENT_HERO_BRAND_LOGO'),
        signatureLogo: findImageUrl(event?.images, 'EVENT_HERO_SIGNATURE_LOGO'),
        organizerLogo: organizerLogoUrl(event?.organizer?.images, 'dark'),
    };
};

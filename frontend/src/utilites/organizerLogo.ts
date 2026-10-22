import {Image} from "../types.ts";
import {hexToRgb, isLightColor} from "./themeUtils.ts";

export type LogoBackground = 'light' | 'dark';

type LogoImage = Pick<Image, 'type' | 'url'>;

export const logoBackgroundFromColor = (color?: string | null): LogoBackground =>
    color && hexToRgb(color) && isLightColor(color) ? 'light' : 'dark';

/**
 * ORGANIZER_LOGO is made for dark or coloured backgrounds, ORGANIZER_EMAIL_LOGO for light ones.
 * The background is either a known surface ('light' / 'dark') or a colour chosen by luminance.
 */
export const findOrganizerLogo = <T extends LogoImage>(
    images: T[] | undefined | null,
    background: LogoBackground | string | undefined | null,
): T | undefined => {
    const surface = background === 'light' || background === 'dark'
        ? background
        : logoBackgroundFromColor(background);
    const logo = images?.find((image) => image.type === 'ORGANIZER_LOGO');

    if (surface === 'dark') {
        return logo;
    }

    return images?.find((image) => image.type === 'ORGANIZER_EMAIL_LOGO') ?? logo;
};

export const organizerLogoUrl = (
    images: LogoImage[] | undefined | null,
    background: LogoBackground | string | undefined | null,
): string | undefined => findOrganizerLogo(images, background)?.url;

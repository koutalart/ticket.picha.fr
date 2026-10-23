import {
    buildHomepageFontUrl,
    DEFAULT_HOMEPAGE_FONT,
    getHomepageFont,
    ORGANIZER_FONT_FALLBACK,
    ORGANIZER_FONT_VALUE,
    sanitizeFontFamilyName,
} from "../constants/homepageFonts.ts";
import {OrganizerCustomFont} from "../types.ts";
import {getConfig} from "./config.ts";
import {isSsr} from "./helpers.ts";

const LINK_ID_PREFIX = 'hi-homepage-font-';
const ORGANIZER_STYLE_ID = 'hi-organizer-font-face';

/**
 * Attribute set on the page wrapper when the organizer font is in use. The generated stylesheet
 * applies the Light (300) weight to text and the Bold (700) weight to headings under it.
 */
export const ORGANIZER_FONT_ATTRIBUTE = 'data-organizer-font';

const ensureBunnyFontLoaded = (fontValue: string): void => {
    const font = getHomepageFont(fontValue);

    if (font.value === DEFAULT_HOMEPAGE_FONT) {
        return;
    }

    const linkId = LINK_ID_PREFIX + font.bunnyFamily;
    if (document.getElementById(linkId)) {
        return;
    }

    const link = document.createElement('link');
    link.id = linkId;
    link.rel = 'stylesheet';
    link.href = buildHomepageFontUrl(font.value);
    document.head.appendChild(link);
};

const originOf = (url: string | undefined): string | null => {
    if (!url) {
        return null;
    }
    try {
        return new URL(url, window.location.href).origin;
    } catch {
        return null;
    }
};

/**
 * Fonts need CORS when loaded from another origin. On an organizer custom domain, the API is also
 * proxied under /api/ on that domain, so files served by the API host are loaded from the current origin.
 */
const resolveFontUrl = (url: string): string => {
    try {
        const parsed = new URL(url, window.location.href);
        const currentOrigin = window.location.origin;
        const apiOrigin = originOf(getConfig('VITE_API_URL_CLIENT'));
        const frontendOrigin = originOf(getConfig('VITE_FRONTEND_URL'));
        const isOnCustomDomain = !!frontendOrigin && frontendOrigin !== currentOrigin;

        if (isOnCustomDomain && parsed.origin !== currentOrigin && parsed.origin === apiOrigin) {
            return currentOrigin + parsed.pathname + parsed.search;
        }

        return parsed.href;
    } catch {
        return url;
    }
};

const escapeCssUrl = (url: string): string => url.replace(/["\\\n\r]/g, (char) => encodeURIComponent(char));

export const buildOrganizerFontCss = (organizerFont: OrganizerCustomFont): string => {
    const family = sanitizeFontFamilyName(organizerFont.family_name);
    const weights = organizerFont.files.map(file => file.weight);

    const faces = organizerFont.files.map(file => [
        '@font-face{',
        `font-family:'${family}';`,
        `src:url("${escapeCssUrl(resolveFontUrl(file.url))}") format("${file.format}");`,
        `font-weight:${Number(file.weight)};`,
        'font-style:normal;',
        'font-display:swap;',
        '}',
    ].join(''));

    const rules: string[] = [];
    if (weights.includes(300)) {
        rules.push(`[${ORGANIZER_FONT_ATTRIBUTE}]{font-weight:300;}`);
    }
    if (weights.includes(700)) {
        rules.push(`[${ORGANIZER_FONT_ATTRIBUTE}] :where(h1,h2,h3,h4,h5,h6,.mantine-Title-root){font-weight:700;}`);
    }

    return [...faces, ...rules].join('\n');
};

const ensureOrganizerFontLoaded = (organizerFont: OrganizerCustomFont | null | undefined): void => {
    ensureBunnyFontLoaded(ORGANIZER_FONT_FALLBACK);

    if (!organizerFont?.family_name || !organizerFont.files?.length) {
        return;
    }

    const css = buildOrganizerFontCss(organizerFont);
    let style = document.getElementById(ORGANIZER_STYLE_ID) as HTMLStyleElement | null;

    if (style?.textContent === css) {
        return;
    }

    if (!style) {
        style = document.createElement('style');
        style.id = ORGANIZER_STYLE_ID;
        document.head.appendChild(style);
    }

    style.textContent = css;
};

export const ensureHomepageFontLoaded = (
    fontValue: string | null | undefined,
    organizerFont?: OrganizerCustomFont | null,
): void => {
    if (isSsr() || typeof document === 'undefined') {
        return;
    }

    if (fontValue === ORGANIZER_FONT_VALUE) {
        ensureOrganizerFontLoaded(organizerFont);
        return;
    }

    ensureBunnyFontLoaded(fontValue || DEFAULT_HOMEPAGE_FONT);
};

import {CSSProperties, useEffect} from "react";
import {Event} from "../../../types.ts";
import {getFullscreenHero} from "../../../utilites/fullscreenHero.ts";
import {buildHomepageFontStack, isOrganizerFontActive} from "../../../constants/homepageFonts.ts";
import {ensureHomepageFontLoaded} from "../../../utilites/fontLoader.ts";
import {getContrastColor, hexToRgb} from "../../../utilites/themeUtils.ts";
import {useConsentBannerTheme} from "../../../utilites/consentBannerTheme.ts";

const DEFAULT_ACCENT = '#8b5cf6';

export const useEventImmersive = (event?: Event) => {
    const hero = getFullscreenHero(event);
    const fontFamily = hero.settings.font_family;
    const organizerFont = event?.organizer?.custom_font;
    const enabled = hero.enabled;

    useEffect(() => {
        if (enabled) {
            ensureHomepageFontLoaded(fontFamily, organizerFont);
        }
    }, [enabled, fontFamily, organizerFont]);

    const accent = hero.settings.accent || DEFAULT_ACCENT;
    const accentContrast = getContrastColor(accent);
    const rgb = hexToRgb(accent);
    const accentTint = rgb ? `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, 0.16)` : 'rgba(255, 255, 255, 0.1)';
    const fontStack = buildHomepageFontStack(fontFamily, organizerFont);

    useConsentBannerTheme(enabled ? accent : undefined, 'dark', fontStack);

    const style = {
        '--immersive-accent': accent,
        '--immersive-accent-contrast': accentContrast,
        '--immersive-accent-tint': accentTint,
        '--immersive-font-family': fontStack,
        '--theme-accent': accent,
        '--theme-accent-contrast': accentContrast,
        '--theme-accent-tint-10': accentTint,
        '--theme-background': 'transparent',
        '--theme-surface': 'transparent',
        '--theme-border': 'rgba(255, 255, 255, 0.14)',
        '--theme-text-primary': '#ffffff',
        '--theme-text-secondary': 'rgba(255, 255, 255, 0.74)',
        '--theme-text-tertiary': 'rgba(255, 255, 255, 0.56)',
        '--theme-font-family': fontStack,
        fontFamily: fontStack,
    } as CSSProperties;

    return {
        enabled,
        hero,
        accent,
        fontStack,
        organizerFontActive: isOrganizerFontActive(fontFamily, organizerFont),
        style,
    };
};

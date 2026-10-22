import {MantineColorsTuple} from "@mantine/core";
import {getContrastRatio, hexToRgb} from "../../../utilites/themeUtils.ts";

export type PublicThemeMode = 'light' | 'dark';

export interface PublicPalette {
    surface: string;
    background: string;
    textPrimary: string;
    textSecondary: string;
    textTertiary: string;
    border: string;
}

/**
 * Fixed palettes for public pages: surfaces, text and borders are not customizable so that
 * content stays readable whatever accent color the organizer picks.
 */
export const LIGHT_PALETTE: PublicPalette = {
    surface: '#ffffff',
    background: '#f8f9fa',
    textPrimary: '#1a1a1a',
    textSecondary: '#525252',
    textTertiary: '#737373',
    border: '#e5e7eb',
};

export const DARK_PALETTE: PublicPalette = {
    surface: '#1f1f1f',
    background: '#121212',
    textPrimary: '#ffffff',
    textSecondary: '#a3a3a3',
    textTertiary: '#737373',
    border: '#333333',
};

export const getPublicPalette = (mode: PublicThemeMode): PublicPalette => mode === 'dark' ? DARK_PALETTE : LIGHT_PALETTE;

/**
 * Creates a Mantine color tuple that preserves the organizer's exact accent color.
 */
export function createAccentPalette(accentColor: string): MantineColorsTuple {
    const rgb = hexToRgb(accentColor);
    if (!rgb) {
        return ['#f3e8ff', '#e9d5ff', '#d8b4fe', '#c084fc', '#a855f7', '#9333ea', '#7c3aed', '#6d28d9', '#5b21b6', '#4c1d95'];
    }

    const {r, g, b} = rgb;

    const lighten = (factor: number) => {
        const lr = Math.round(r + (255 - r) * factor);
        const lg = Math.round(g + (255 - g) * factor);
        const lb = Math.round(b + (255 - b) * factor);
        return `rgb(${lr}, ${lg}, ${lb})`;
    };

    const darken = (factor: number) => {
        const dr = Math.round(r * (1 - factor));
        const dg = Math.round(g * (1 - factor));
        const db = Math.round(b * (1 - factor));
        return `rgb(${dr}, ${dg}, ${db})`;
    };

    return [
        lighten(0.9),
        lighten(0.8),
        lighten(0.6),
        lighten(0.4),
        lighten(0.2),
        accentColor,
        accentColor,
        accentColor,
        darken(0.15),
        darken(0.3),
    ];
}

const toHex = (value: number) => Math.round(value).toString(16).padStart(2, '0');

/**
 * Returns the accent color, darkened on light surfaces or lightened on dark ones until it reaches
 * the WCAG contrast ratio required for text (4.5:1 by default).
 */
export function readableAccent(accentColor: string, surface: string, minRatio = 4.5): string {
    const rgb = hexToRgb(accentColor);
    if (!rgb) {
        return accentColor;
    }

    const towardsWhite = getContrastRatio('#ffffff', surface) > getContrastRatio('#000000', surface);
    const target = towardsWhite ? 255 : 0;

    for (let step = 0; step <= 20; step++) {
        const ratio = step / 20;
        const candidate = `#${toHex(rgb.r + (target - rgb.r) * ratio)}${toHex(rgb.g + (target - rgb.g) * ratio)}${toHex(rgb.b + (target - rgb.b) * ratio)}`;
        if (getContrastRatio(candidate, surface) >= minRatio) {
            return candidate;
        }
    }

    return towardsWhite ? '#ffffff' : '#000000';
}

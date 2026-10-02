import {MantineColorsTuple} from "@mantine/core";

const PRECOMPUTED_PALETTES: Record<string, MantineColorsTuple> = {
    '#40296c': ["#f3f0f9", "#e3dded", "#c5b8dd", "#a691cd", "#8b6fbf", "#7b5ab8", "#734fb5", "#61409f", "#57398e", "#40296c"],
    '#6b4baf': ["#f5efff", "#e5ddf4", "#c6b9e2", "#a793d1", "#8c72c2", "#7b5eb9", "#6b4baf", "#6144a0", "#563c90", "#4a3280"],
};

const WHITE_MIX = [0.92, 0.8, 0.62, 0.45, 0.3, 0.18, 0.08, 0];
const BLACK_MIX = [0.12, 0.25];

const toRgb = (hex: string): [number, number, number] => {
    const value = hex.replace('#', '');
    const full = value.length === 3 ? value.split('').map((c) => c + c).join('') : value;
    return [0, 2, 4].map((i) => parseInt(full.slice(i, i + 2), 16)) as [number, number, number];
};

const toHex = (rgb: number[]): string =>
    '#' + rgb.map((c) => Math.round(Math.min(255, Math.max(0, c))).toString(16).padStart(2, '0')).join('');

const mix = (rgb: [number, number, number], target: number, amount: number): string =>
    toHex(rgb.map((c) => c + (target - c) * amount));

export const generatePalette = (color: string): MantineColorsTuple => {
    const key = color.trim().toLowerCase();
    if (PRECOMPUTED_PALETTES[key]) {
        return PRECOMPUTED_PALETTES[key];
    }

    const rgb = toRgb(key);
    return [
        ...WHITE_MIX.map((amount) => mix(rgb, 255, amount)),
        ...BLACK_MIX.map((amount) => mix(rgb, 0, amount)),
    ] as unknown as MantineColorsTuple;
};

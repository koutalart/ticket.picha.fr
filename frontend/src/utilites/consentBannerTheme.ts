import {useEffect} from "react";

export interface ConsentBannerTheme {
    accentColor: string;
    mode: 'light' | 'dark';
    fontFamily?: string;
}

/**
 * Lets the public page currently displayed give its palette to the consent banner, which is rendered
 * once for the whole app outside the page tree.
 */
let entries: { id: number; theme: ConsentBannerTheme }[] = [];
let nextId = 0;
const listeners = new Set<() => void>();

const notify = () => listeners.forEach(listener => listener());

export const subscribeConsentBannerTheme = (listener: () => void): (() => void) => {
    listeners.add(listener);
    return () => listeners.delete(listener);
};

export const getConsentBannerTheme = (): ConsentBannerTheme | null => entries[entries.length - 1]?.theme ?? null;

export const registerConsentBannerTheme = (theme: ConsentBannerTheme): (() => void) => {
    const id = nextId++;
    entries = [...entries, {id, theme}];
    notify();

    return () => {
        entries = entries.filter(entry => entry.id !== id);
        notify();
    };
};

export const useConsentBannerTheme = (accentColor: string | undefined, mode: 'light' | 'dark' | undefined, fontFamily?: string): void => {
    useEffect(() => {
        if (!accentColor || !mode) return;
        return registerConsentBannerTheme({accentColor, mode, fontFamily});
    }, [accentColor, mode, fontFamily]);
};

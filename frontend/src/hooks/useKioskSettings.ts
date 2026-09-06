import {useCallback, useEffect, useState} from "react";
import {isSsr} from "../utilites/helpers.ts";
import {SupportedLocales} from "../locales.ts";

export const KIOSK_SETTINGS_STORAGE_KEY = 'picha_kiosk_settings';

export interface KioskSettings {
    stationName: string;
    defaultTicketLocale: SupportedLocales | '';
    sendConfirmationEmail: boolean;
}

const DEFAULT_SETTINGS: KioskSettings = {
    stationName: '',
    defaultTicketLocale: '',
    sendConfirmationEmail: false,
};

const readSettings = (): KioskSettings => {
    if (isSsr()) {
        return DEFAULT_SETTINGS;
    }

    try {
        const raw = window.localStorage.getItem(KIOSK_SETTINGS_STORAGE_KEY);
        if (!raw) {
            return DEFAULT_SETTINGS;
        }
        const parsed = JSON.parse(raw) as Partial<KioskSettings>;
        return {
            stationName: typeof parsed.stationName === 'string' ? parsed.stationName : '',
            defaultTicketLocale: parsed.defaultTicketLocale ?? '',
            sendConfirmationEmail: Boolean(parsed.sendConfirmationEmail),
        };
    } catch {
        return DEFAULT_SETTINGS;
    }
};

const writeSettings = (settings: KioskSettings): void => {
    if (isSsr()) {
        return;
    }

    try {
        window.localStorage.setItem(KIOSK_SETTINGS_STORAGE_KEY, JSON.stringify(settings));
    } catch {
        // Ignore quota / private-mode failures.
    }
};

export const useKioskSettings = () => {
    const [isHydrated, setIsHydrated] = useState(false);
    const [settings, setSettingsState] = useState<KioskSettings>(DEFAULT_SETTINGS);

    useEffect(() => {
        setSettingsState(readSettings());
        setIsHydrated(true);
    }, []);

    const setSettings = useCallback((next: KioskSettings) => {
        setSettingsState(next);
        writeSettings(next);
    }, []);

    return {settings, setSettings, isHydrated};
};

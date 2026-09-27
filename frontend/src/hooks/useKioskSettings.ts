import {useCallback, useEffect, useState} from "react";
import {isSsr} from "../utilites/helpers.ts";
import {SupportedLocales} from "../locales.ts";

export const KIOSK_SETTINGS_STORAGE_KEY = 'picha_kiosk_settings';

export type KioskPrintOutput = 'none' | 'zebra';

export const SUPPORTED_PRINTER_DPI = [203, 300, 600] as const;

export type PrinterDpi = typeof SUPPORTED_PRINTER_DPI[number];

export interface ZebraLabelFormat {
    printerDpi: PrinterDpi;
    labelWidthMm: number;
    labelLengthMm: number;
}

export const DEFAULT_ZEBRA_LABEL_FORMAT: ZebraLabelFormat = {
    printerDpi: 203,
    labelWidthMm: 80,
    labelLengthMm: 101,
};

const readMillimetres = (value: unknown, fallback: number, min: number, max: number): number => {
    return typeof value === 'number' && Number.isFinite(value) && value >= min && value <= max ? value : fallback;
};

export interface KioskSettings {
    stationName: string;
    defaultTicketLocale: SupportedLocales | '';
    sendConfirmationEmail: boolean;
    printOutput: KioskPrintOutput;
    zebraPrinterHost: string;
    phoneCallingCode: string;
    printerDpi: PrinterDpi;
    labelWidthMm: number;
    labelLengthMm: number;
}

const DEFAULT_SETTINGS: KioskSettings = {
    stationName: '',
    defaultTicketLocale: '',
    sendConfirmationEmail: false,
    printOutput: 'zebra',
    zebraPrinterHost: '',
    phoneCallingCode: '',
    ...DEFAULT_ZEBRA_LABEL_FORMAT,
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
            printOutput: parsed.printOutput === 'none' ? 'none' : 'zebra',
            zebraPrinterHost: typeof parsed.zebraPrinterHost === 'string' ? parsed.zebraPrinterHost : '',
            phoneCallingCode: typeof parsed.phoneCallingCode === 'string'
                ? parsed.phoneCallingCode.replace(/\D/g, '')
                : '',
            printerDpi: SUPPORTED_PRINTER_DPI.includes(parsed.printerDpi as PrinterDpi)
                ? parsed.printerDpi as PrinterDpi
                : DEFAULT_ZEBRA_LABEL_FORMAT.printerDpi,
            labelWidthMm: readMillimetres(parsed.labelWidthMm, DEFAULT_ZEBRA_LABEL_FORMAT.labelWidthMm, 20, 108),
            labelLengthMm: readMillimetres(parsed.labelLengthMm, DEFAULT_ZEBRA_LABEL_FORMAT.labelLengthMm, 20, 300),
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

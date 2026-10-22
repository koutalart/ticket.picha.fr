import {i18n} from "@lingui/core";

export type SupportedLocales =
    "en"
    | "de"
    | "fr"
    | "it"
    | "nl"
    | "pt"
    | "es"
    | "zh-cn"
    | "pt-br"
    | "vi"
    | "zh-hk"
    | "tr"
    | "hu"
    | "pl"
    | "se"
    | "el";

export const availableLocales = ["en", "de", "fr", "it", "nl", "pt", "es", "zh-cn", "zh-hk", "pt-br", "vi", "tr", "hu", "pl", "se", "el"];

export const localeToFlagEmojiMap: Record<SupportedLocales, string> = {
    en: '🇬🇧',
    de: '🇩🇪',
    fr: '🇫🇷',
    it: '🇮🇹',
    nl: '🇳🇱',
    pt: '🇵🇹',
    es: '🇪🇸',
    "zh-cn": '🇨🇳',
    "zh-hk": '🇭🇰',
    "pt-br": '🇧🇷',
    vi: '🇻🇳',
    tr: '🇹🇷',
    hu: '🇭🇺',
    pl: '🇵🇱',
    se: '🇸🇪',
    el: '🇬🇷',
};

export const localeToNameMap: Record<SupportedLocales, string> = {
    en: `English`,
    de: `German`,
    fr: `French`,
    it: `Italian`,
    nl: `Dutch`,
    pt: `Portuguese`,
    es: `Spanish`,
    "zh-cn": `Chinese`,
    "zh-hk": `Cantonese`,
    "pt-br": `Portuguese (Brazil)`,
    vi: `Vietnamese`,
    tr: `Turkish`,
    hu: `Hungarian`,
    pl: `Polish`,
    se: `Swedish`,
    el: `Greek`,
};

const htmlLangOverrides: Record<string, string> = {
    se: "sv",
    "zh-cn": "zh-CN",
    "zh-hk": "zh-HK",
    "pt-br": "pt-BR",
};

export const toHtmlLang = (locale: string): string => htmlLangOverrides[locale] || locale;

export const getClientLocale = () => {
    if (typeof window !== "undefined") {
        const storedLocale = document
            .cookie
            .split(";")
            .find((c) => c.includes("locale="))
            ?.split("=")[1];

        if (storedLocale) {
            return getSupportedLocale(storedLocale);
        }

        const serverLocale = document.documentElement.dataset.locale;
        if (serverLocale) {
            return getSupportedLocale(serverLocale);
        }

        return getSupportedLocale(window.navigator.language);
    }

    return getSupportedLocale(i18n.locale || "en");
};

export type CustomerAddressForm = 'vous' | 'tu';

let customerAddressForm: CustomerAddressForm = 'vous';
const customerAddressFormListeners = new Set<() => void>();

export const getCustomerAddressForm = (): CustomerAddressForm => customerAddressForm;

export const normalizeCustomerAddressForm = (value?: string | null): CustomerAddressForm =>
    value === 'tu' ? 'tu' : 'vous';

export const subscribeToCustomerAddressForm = (listener: () => void) => {
    customerAddressFormListeners.add(listener);
    return () => {
        customerAddressFormListeners.delete(listener);
    };
};

/**
 * Sets the address form without reloading the catalog. Used before the first activation
 * (server render, client boot) so the catalog is loaded once with the right variant.
 */
export const initCustomerAddressForm = (value?: string | null) => {
    customerAddressForm = normalizeCustomerAddressForm(value);
};

export async function setCustomerAddressForm(value?: string | null) {
    const next = normalizeCustomerAddressForm(value);
    if (next === customerAddressForm) {
        return;
    }

    customerAddressForm = next;

    if (i18n.locale === 'fr') {
        await dynamicActivateLocale('fr');
    }

    customerAddressFormListeners.forEach((listener) => listener());
}

export async function dynamicActivateLocale(locale: string) {
    try {
        locale = availableLocales.includes(locale) ? locale : "en";
        const catalog = locale === 'fr' && customerAddressForm === 'tu' ? 'fr-tu' : locale;
        const module = (await import(`./locales/${catalog}.po`));
        i18n.loadAndActivate({locale, messages: {...module.messages}});
    } catch (error) {
        console.error("Error loading locale:", error);
        // i18n.activate("en");
    }
}

export const getSupportedLocale = (userLocale: string) => {
    const normalizedLocale = userLocale.toLowerCase();

    if (availableLocales.includes(normalizedLocale)) {
        return normalizedLocale;
    }

    const mainLanguage = normalizedLocale.split('-')[0];
    const mainLocale = availableLocales.find(locale => locale.startsWith(mainLanguage));
    if (mainLocale) {
        return mainLocale;
    }

    return "en";
};

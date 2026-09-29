import {t} from "@lingui/macro";
import {SupportedLocales} from "../locales.ts";

// Evaluated at call time so the label follows the active Lingui locale.
export const getLocaleName = (locale: SupportedLocales): string => {
    switch (locale) {
        case "hu":
            return t`Hungarian`;
        case "de":
            return t`German`;
        case "en":
            return t`English`;
        case "es":
            return t`Spanish`;
        case "fr":
            return t`French`;
        case "it":
            return t`Italian`;
        case "nl":
            return t`Dutch`;
        case "pt":
            return t`Portuguese`;
        case "pt-br":
            return t`Brazilian Portuguese`;
        case "zh-cn":
            return t`Chinese (Simplified)`;
        case "zh-hk":
            return t`Chinese (Traditional)`;
        case "vi":
            return t`Vietnamese`;
        case "tr":
            return t`Turkish`;
        case "pl":
            return t`Polish`;
        case "se":
            return t`Swedish`;
        case "el":
            return t`Greek`;
        default:
            // Defensive fallback: if a new locale is added to SupportedLocales
            // but not handled here, return the locale code itself rather than
            // undefined. An undefined label propagates into Mantine's Combobox
            // `defaultOptionsFilter`, which calls `.toLowerCase()` on it and
            // throws during SSR, 500-ing every auth page.
            return locale;
    }
};

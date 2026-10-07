import {getConfig} from "./config.ts";

export const DEFAULT_APP_NAME = "PICHA Ticket";

export const getAppName = (): string => getConfig("VITE_APP_NAME", DEFAULT_APP_NAME) as string;

/** Logo for light backgrounds (purple wordmark). */
export const getLogoForLightBackground = (): string =>
    getConfig("VITE_APP_LOGO_DARK", "/logos/picha-ai.png") as string;

/** Logo for dark backgrounds (white wordmark). */
export const getLogoForDarkBackground = (): string =>
    getConfig("VITE_APP_LOGO_LIGHT", "/logos/picha-ai-on-dark.png") as string;

export const getTermsOfUseUrl = (): string => getConfig("VITE_TOS_URL", "/legal/cgu") as string;

export const getTermsOfSaleUrl = (): string => getConfig("VITE_TOS_SALE_URL", "/legal/cgv") as string;

export const getPrivacyPolicyUrl = (): string => getConfig("VITE_PRIVACY_URL", "/legal/confidentialite") as string;

export const SHARE_IMAGE_PATH = "/images/og-picha-ticket.png";

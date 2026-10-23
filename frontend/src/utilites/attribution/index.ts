import {isSsr} from "../helpers.ts";
import {getConfig} from "../config.ts";
import {
    ConsentChoice,
    getConsentChoice,
    getGrantedCategories,
    isAudienceMeasurementAllowed,
} from "../trackingPixels/consent.ts";
import {
    ATTRIBUTION_RETENTION_MS,
    AttributionState,
    buildTouch,
    CLICK_ID_KEYS,
    ClickIds,
    cookieDomainsFor,
    fbcCookieValue,
    fbclidFromFbc,
    fbpCookieValue,
    gclAwCookieValue,
    isAttributionExpired,
    mergeTouch,
    readClickIds,
    stripMarketingFields,
} from "./core.ts";

/**
 * First party attribution of visits on the current host (platform or organizer custom domain):
 * first and last touch for 90 days, advertising click ids only with the marketing consent.
 */

const ATTRIBUTION_KEY = 'picha_attribution';
const CLICK_IDS_KEY = 'picha_ad_clicks';
const AD_COOKIES = ['_fbc', '_fbp', '_gcl_aw'];
const AD_COOKIE_MAX_AGE = ATTRIBUTION_RETENTION_MS / 1000;

let pageTouchCapturedAt: number | null = null;

const readJson = <T, >(key: string): T | null => {
    try {
        const raw = localStorage.getItem(key);
        return raw ? JSON.parse(raw) as T : null;
    } catch {
        return null;
    }
};

const writeJson = (key: string, value: unknown): void => {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Storage can be unavailable (private mode); attribution is best effort.
    }
};

const removeItem = (key: string): void => {
    try {
        localStorage.removeItem(key);
    } catch {
        // Nothing stored.
    }
};

export const readCookie = (name: string): string | null => {
    if (typeof document === 'undefined') return null;
    const match = document.cookie.match(new RegExp(`(?:^|;\\s*)${name}=([^;]*)`));
    return match ? decodeURIComponent(match[1]) : null;
};

const writeAdCookie = (name: string, value: string): void => {
    const secure = window.location.protocol === 'https:' ? ';Secure' : '';
    document.cookie = `${name}=${encodeURIComponent(value)};path=/;max-age=${AD_COOKIE_MAX_AGE};SameSite=Lax${secure}`;
};

const deleteCookie = (name: string): void => {
    const expire = `${name}=;path=/;max-age=0;expires=Thu, 01 Jan 1970 00:00:00 GMT`;
    document.cookie = expire;
    cookieDomainsFor(window.location.hostname).forEach(domain => {
        document.cookie = `${expire};domain=.${domain}`;
    });
};

const internalHosts = (): string[] => {
    try {
        const platformUrl = getConfig('VITE_FRONTEND_URL') as string | undefined;
        return platformUrl ? [new URL(platformUrl).host] : [];
    } catch {
        return [];
    }
};

const readState = (): AttributionState | null => {
    const state = readJson<AttributionState>(ATTRIBUTION_KEY);
    if (!state?.first_touch || !state?.last_touch) return null;
    if (isAttributionExpired(state.first_touch.captured_at, Date.now())) {
        removeItem(ATTRIBUTION_KEY);
        return null;
    }
    return state;
};

const readClickIdStore = (): ClickIds | null => {
    const ids = readJson<ClickIds>(CLICK_IDS_KEY);
    if (!ids) return null;
    if (isAttributionExpired(ids.captured_at, Date.now())) {
        removeItem(CLICK_IDS_KEY);
        return null;
    }
    return ids;
};

/**
 * Stores the click ids present in the current URL and sets the Meta and Google first party cookies.
 * Only called with the marketing consent.
 */
const storeClickIdsFromUrl = (): void => {
    const ids = readClickIds(new URLSearchParams(window.location.search));
    if (Object.keys(ids).length === 0) return;

    const now = Date.now();
    writeJson(CLICK_IDS_KEY, {...ids, captured_at: now});

    if (ids.fbclid) {
        if (fbclidFromFbc(readCookie('_fbc')) !== ids.fbclid) {
            writeAdCookie('_fbc', fbcCookieValue(ids.fbclid, now));
        }
        if (!readCookie('_fbp')) {
            writeAdCookie('_fbp', fbpCookieValue(now));
        }
    }

    if (ids.gclid) {
        writeAdCookie('_gcl_aw', gclAwCookieValue(ids.gclid, now));
    }
};

const purgeAdvertisingData = (): void => {
    removeItem(CLICK_IDS_KEY);
    AD_COOKIES.forEach(deleteCookie);

    const state = readState();
    if (state) writeJson(ATTRIBUTION_KEY, stripMarketingFields(state));
};

/**
 * Records the current visit. Called once per page load from the app shell.
 */
export const captureAttribution = (): void => {
    if (isSsr()) return;

    const choice = getConsentChoice();
    const marketing = choice?.marketing === true;

    if (isAudienceMeasurementAllowed()) {
        const now = Date.now();
        const {touch, isNewSource} = buildTouch({
            url: new URL(window.location.href),
            referrer: document.referrer,
            internalHosts: internalHosts(),
            marketing,
            now,
        });
        writeJson(ATTRIBUTION_KEY, mergeTouch(readState(), touch, isNewSource, now));
        pageTouchCapturedAt = now;
    }

    if (marketing) {
        storeClickIdsFromUrl();
    }
};

/**
 * With a consent given on the landing page, the touch recorded a moment ago gets the full referrer and landing URL.
 */
const enrichCurrentPageTouch = (): void => {
    const state = readState();
    if (!state || pageTouchCapturedAt === null) return;

    const {touch} = buildTouch({
        url: new URL(window.location.href),
        referrer: document.referrer,
        internalHosts: internalHosts(),
        marketing: true,
        now: pageTouchCapturedAt,
    });
    const enrich = (current: AttributionState['first_touch']) => current.captured_at === pageTouchCapturedAt
        ? {...current, referrer: touch.referrer, landing_url: touch.landing_url}
        : current;

    writeJson(ATTRIBUTION_KEY, {first_touch: enrich(state.first_touch), last_touch: enrich(state.last_touch)});
};

/**
 * Applies a new consent choice: click ids still in the URL are stored when advertising is accepted,
 * advertising cookies and identifiers are deleted when it is refused or withdrawn.
 */
export const applyConsentToAttribution = (choice: ConsentChoice): void => {
    if (isSsr()) return;

    if (choice.marketing) {
        storeClickIdsFromUrl();
        enrichCurrentPageTouch();
    } else {
        purgeAdvertisingData();
    }

    if (!choice.audience) {
        removeItem(ATTRIBUTION_KEY);
    }
};

export interface AttributionTrackingContext {
    consent: boolean;
    consent_details: {
        categories: string[];
        version: number;
        timestamp: number;
    } | null;
    first_touch?: AttributionState['first_touch'];
    last_touch?: AttributionState['last_touch'];
    fbp?: string | null;
    fbc?: string | null;
    gcl_aw?: string | null;
    fbclid?: string;
    gclid?: string;
    wbraid?: string;
    gbraid?: string;
    ttclid?: string;
    event_source_url?: string;
}

/**
 * What the order creation sends: attribution touches, and advertising identifiers only with consent.
 */
export const getAttributionTrackingContext = (): AttributionTrackingContext | null => {
    if (isSsr()) return null;

    const choice = getConsentChoice();
    const marketing = choice?.marketing === true;
    const state = isAudienceMeasurementAllowed() ? readState() : null;

    const context: AttributionTrackingContext = {
        consent: marketing,
        consent_details: choice ? {
            categories: getGrantedCategories(choice),
            version: choice.version,
            timestamp: choice.timestamp,
        } : null,
        ...(state ? {first_touch: state.first_touch, last_touch: state.last_touch} : {}),
    };

    if (!marketing) {
        return context;
    }

    const clickIds = readClickIdStore();
    CLICK_ID_KEYS.forEach(key => {
        const value = clickIds?.[key];
        if (value) context[key] = value;
    });

    return {
        ...context,
        fbp: readCookie('_fbp'),
        fbc: readCookie('_fbc'),
        gcl_aw: readCookie('_gcl_aw'),
        event_source_url: window.location.href,
    };
};

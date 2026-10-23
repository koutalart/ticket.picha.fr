export type ConsentState = 'granted' | 'denied' | 'pending';

export type ConsentCategory = 'necessary' | 'audience' | 'marketing';

export interface ConsentChoice {
    marketing: boolean;
    audience: boolean;
    version: number;
    timestamp: number;
}

/**
 * Bump when the purposes or the list of advertising tools change: visitors are asked again.
 */
export const CONSENT_VERSION = 2;

export const CONSENT_CHANGE_EVENT = 'hi_consent_change';
export const CONSENT_OPEN_EVENT = 'picha_consent_open';

const CONSENT_COOKIE = 'hi_tracking_consent';
const CONSENT_MAX_AGE = 182 * 24 * 60 * 60; // 6 months, CNIL recommendation
const CHOICE_PATTERN = /^v(\d{1,3})\.(\d{10,13})\.([01])\.([01])$/;

const readConsentCookie = (): string | null => {
    if (typeof document === 'undefined') return null;
    const match = document.cookie.match(new RegExp(`(?:^|;\\s*)${CONSENT_COOKIE}=([^;]*)`));
    return match ? decodeURIComponent(match[1]) : null;
};

/**
 * The visitor's choice on this host, or null when the banner must be shown. A refusal given with the
 * previous banner (cookie value "denied") is kept; an older acceptance is asked again because the
 * purposes changed.
 */
export function getConsentChoice(): ConsentChoice | null {
    const value = readConsentCookie();
    if (!value) return null;

    if (value === 'denied') {
        return {marketing: false, audience: true, version: 1, timestamp: 0};
    }

    const match = value.match(CHOICE_PATTERN);
    if (!match || Number(match[1]) !== CONSENT_VERSION) return null;

    return {
        version: Number(match[1]),
        timestamp: Number(match[2]),
        marketing: match[3] === '1',
        audience: match[4] === '1',
    };
}

export function getConsentState(): ConsentState {
    const choice = getConsentChoice();
    if (!choice) return 'pending';
    return choice.marketing ? 'granted' : 'denied';
}

export function isConsentPending(): boolean {
    return getConsentChoice() === null;
}

export function isAudienceMeasurementAllowed(): boolean {
    return getConsentChoice()?.audience ?? true;
}

export function getGrantedCategories(choice: ConsentChoice | null = getConsentChoice()): ConsentCategory[] {
    return [
        'necessary',
        ...(choice?.audience ?? true ? ['audience' as const] : []),
        ...(choice?.marketing ? ['marketing' as const] : []),
    ];
}

export function writeConsentChoice(choice: { marketing: boolean; audience: boolean }): ConsentChoice {
    const saved: ConsentChoice = {...choice, version: CONSENT_VERSION, timestamp: Date.now()};
    if (typeof document === 'undefined') return saved;

    const secure = window.location?.protocol === 'https:' ? ';Secure' : '';
    const value = `v${saved.version}.${saved.timestamp}.${saved.marketing ? 1 : 0}.${saved.audience ? 1 : 0}`;
    document.cookie = `${CONSENT_COOKIE}=${value};path=/;max-age=${CONSENT_MAX_AGE};SameSite=Lax${secure}`;

    return saved;
}

/**
 * Asks the consent manager to show the banner with the detailed choices ("Gérer mes traceurs").
 */
export function openConsentPreferences(): void {
    if (typeof window === 'undefined') return;
    window.dispatchEvent(new CustomEvent(CONSENT_OPEN_EVENT));
}

/**
 * Google Consent Mode v2 integration.
 * Must be called BEFORE any Google tags load.
 * Sets default denied state for EU compliance.
 */
export function initGoogleConsentMode(granted = false): void {
    if (typeof window === 'undefined') return;

    window.dataLayer = window.dataLayer || [];
    function gtag() {
        // eslint-disable-next-line prefer-rest-params
        window.dataLayer!.push(arguments);
    }

    const state = granted ? 'granted' : 'denied';
    // @ts-expect-error gtag uses arguments object
    gtag('consent', 'default', {
        ad_storage: state,
        analytics_storage: state,
        ad_user_data: state,
        ad_personalization: state,
        ...(!granted && {wait_for_update: 500}),
    });
}

export function updateGoogleConsentMode(granted: boolean): void {
    if (typeof window === 'undefined') return;

    window.dataLayer = window.dataLayer || [];
    function gtag() {
        // eslint-disable-next-line prefer-rest-params
        window.dataLayer!.push(arguments);
    }

    const state = granted ? 'granted' : 'denied';
    // @ts-expect-error gtag uses arguments object
    gtag('consent', 'update', {
        ad_storage: state,
        analytics_storage: state,
        ad_user_data: state,
        ad_personalization: state,
    });
}

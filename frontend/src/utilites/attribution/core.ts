/**
 * Pure helpers for visit attribution (no DOM, no storage) so they can be unit tested with node.
 */

export const ATTRIBUTION_RETENTION_MS = 90 * 24 * 60 * 60 * 1000;

export const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as const;
export const CLICK_ID_KEYS = ['fbclid', 'gclid', 'wbraid', 'gbraid', 'ttclid'] as const;

export type UtmKey = typeof UTM_KEYS[number];
export type ClickIdKey = typeof CLICK_ID_KEYS[number];

export type AttributionTouch = Partial<Record<UtmKey, string>> & {
    referrer_domain?: string;
    referrer?: string;
    landing_path: string;
    landing_url?: string;
    captured_at: number;
};

export interface AttributionState {
    first_touch: AttributionTouch;
    last_touch: AttributionTouch;
}

export type ClickIds = Partial<Record<ClickIdKey, string>> & { captured_at: number };

const UTM_MAX_LENGTH = 150;
const CLICK_ID_PATTERN = /^[A-Za-z0-9_\-.]{1,500}$/;
const PAYMENT_HOSTS = ['stripe.com', 'paypal.com', 'stripe.network'];

export const normalizeHost = (host: string): string => host.toLowerCase().replace(/:\d+$/, '').replace(/^www\./, '');

const matchesHost = (host: string, candidate: string) => host === candidate || host.endsWith(`.${candidate}`);

/**
 * The referrer when it comes from another site: same host, the platform host and payment providers
 * returning from a checkout are internal navigations, not sources.
 */
export const externalReferrer = (referrer: string | null | undefined, currentHost: string, internalHosts: string[] = []): URL | null => {
    if (!referrer) return null;

    let url: URL;
    try {
        url = new URL(referrer);
    } catch {
        return null;
    }

    if (url.protocol !== 'http:' && url.protocol !== 'https:') return null;

    const host = normalizeHost(url.host);
    const internal = [normalizeHost(currentHost), ...internalHosts.filter(Boolean).map(normalizeHost)];
    if (!host || internal.includes(host) || PAYMENT_HOSTS.some(payment => matchesHost(host, payment))) {
        return null;
    }

    return url;
};

const cleanValue = (value: string | null): string | null => {
    const clean = value?.replace(/[\u0000-\u001F\u007F<>"'`]/g, '').trim();
    return clean ? clean.slice(0, UTM_MAX_LENGTH) : null;
};

export const readUtm = (params: URLSearchParams): Partial<Record<UtmKey, string>> => {
    const utm: Partial<Record<UtmKey, string>> = {};
    UTM_KEYS.forEach(key => {
        const value = cleanValue(params.get(key));
        if (value) utm[key] = value;
    });
    return utm;
};

export const readClickIds = (params: URLSearchParams): Partial<Record<ClickIdKey, string>> => {
    const ids: Partial<Record<ClickIdKey, string>> = {};
    CLICK_ID_KEYS.forEach(key => {
        const value = params.get(key)?.trim();
        if (value && CLICK_ID_PATTERN.test(value)) ids[key] = value;
    });
    return ids;
};

interface BuildTouchInput {
    url: URL;
    referrer?: string | null;
    internalHosts?: string[];
    marketing: boolean;
    now: number;
}

/**
 * Builds the touch of the current visit. Without the marketing consent only exempt audience data is kept:
 * utm parameters, the referring domain and the landing path.
 */
export const buildTouch = ({url, referrer, internalHosts = [], marketing, now}: BuildTouchInput): {
    touch: AttributionTouch;
    isNewSource: boolean;
} => {
    const utm = readUtm(url.searchParams);
    const ref = externalReferrer(referrer, url.host, internalHosts);
    const hasClickId = Object.keys(readClickIds(url.searchParams)).length > 0;

    const touch: AttributionTouch = {
        ...utm,
        ...(ref ? {referrer_domain: normalizeHost(ref.host)} : {}),
        landing_path: url.pathname.slice(0, 512) || '/',
        captured_at: now,
    };

    if (marketing) {
        if (ref) touch.referrer = `${ref.origin}${ref.pathname}`.slice(0, 2048);
        touch.landing_url = url.href.slice(0, 2048);
    }

    return {touch, isNewSource: Object.keys(utm).length > 0 || hasClickId || ref !== null};
};

export const isAttributionExpired = (capturedAt: number | undefined, now: number): boolean =>
    typeof capturedAt !== 'number' || now - capturedAt > ATTRIBUTION_RETENTION_MS || capturedAt - now > 24 * 60 * 60 * 1000;

/**
 * First touch is kept for 90 days; last touch is replaced only when a new campaign parameter, click id
 * or external referrer arrives.
 */
export const mergeTouch = (
    state: AttributionState | null,
    touch: AttributionTouch,
    isNewSource: boolean,
    now: number,
): AttributionState => {
    if (!state || isAttributionExpired(state.first_touch?.captured_at, now)) {
        return {first_touch: touch, last_touch: touch};
    }

    return isNewSource ? {first_touch: state.first_touch, last_touch: touch} : state;
};

const withoutMarketingFields = (touch: AttributionTouch): AttributionTouch => {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    const {referrer, landing_url, ...rest} = touch;
    return rest;
};

export const stripMarketingFields = (state: AttributionState): AttributionState => ({
    first_touch: withoutMarketingFields(state.first_touch),
    last_touch: withoutMarketingFields(state.last_touch),
});

export const fbcCookieValue = (fbclid: string, nowMs: number): string => `fb.1.${nowMs}.${fbclid}`;

export const fbpCookieValue = (nowMs: number, random: () => number = Math.random): string =>
    `fb.1.${nowMs}.${Math.floor(1_000_000_000 + random() * 9_000_000_000)}`;

export const gclAwCookieValue = (gclid: string, nowMs: number): string => `GCL.${Math.floor(nowMs / 1000)}.${gclid}`;

/**
 * The fbclid stored in an _fbc cookie (fb.1.<timestamp>.<fbclid>).
 */
export const fbclidFromFbc = (fbc: string | null): string | null => {
    const match = fbc?.match(/^fb\.\d\.\d+\.(.+)$/);
    return match ? match[1] : null;
};

/**
 * The parent domains a cookie may have been written on (host and every parent except the top level domain).
 */
export const cookieDomainsFor = (host: string): string[] => {
    const parts = normalizeHost(host).split('.');
    if (parts.length < 2) return [];
    const domains: string[] = [];
    for (let i = 0; i <= parts.length - 2; i++) {
        domains.push(parts.slice(i).join('.'));
    }
    return domains;
};

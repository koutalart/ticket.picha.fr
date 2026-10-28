// Campaign parameters and advertising click ids carried through the /q/:shortId redirect, so the landing page
// still sees the ad click (fbclid becomes the _fbc cookie, utm_* the attribution touch).
const FORWARDED_PARAMS = [
    'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id',
    'fbclid', 'gclid', 'wbraid', 'gbraid', 'ttclid', 'ScCid',
];

const MAX_VALUE_LENGTH = 500;

export const forwardTrackingParams = (redirectUrl, query, baseUrl) => {
    if (!query || typeof query !== 'object') {
        return redirectUrl;
    }

    let target;
    try {
        target = new URL(redirectUrl, baseUrl);
    } catch {
        return redirectUrl;
    }

    if (target.protocol !== 'http:' && target.protocol !== 'https:') {
        return redirectUrl;
    }

    let changed = false;
    FORWARDED_PARAMS.forEach((key) => {
        const value = query[key];
        if (typeof value !== 'string' || value === '' || value.length > MAX_VALUE_LENGTH || target.searchParams.has(key)) {
            return;
        }
        target.searchParams.set(key, value);
        changed = true;
    });

    return changed ? target.toString() : redirectUrl;
};

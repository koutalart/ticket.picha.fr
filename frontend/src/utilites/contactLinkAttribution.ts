import {isSsr} from "./helpers.ts";
import {publicApi} from "../api/public-client";

const STORAGE_KEY = 'picha_contact_link';
const QUERY_PARAM = 'c';
const EXPIRY_DAYS = 30;
const CODE_PATTERN = /^[a-z0-9]{6,16}$/;

let landingVisitRecorded = false;

const normalizeContactCode = (code: string | null | undefined): string | null => {
    const normalized = code?.trim().toLowerCase();
    return normalized && CODE_PATTERN.test(normalized) ? normalized : null;
};

export const captureContactCodeFromUrl = (): void => {
    if (isSsr()) return;

    const code = normalizeContactCode(new URLSearchParams(window.location.search).get(QUERY_PARAM));
    if (!code) return;

    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({code, timestamp: Date.now()}));
    } catch {
        // Storage can be unavailable (private mode); attribution is best effort.
    }

    if (landingVisitRecorded) return;
    landingVisitRecorded = true;

    publicApi.post(`/contact-links/${code}/visits`, {referrer: document.referrer || null}).catch(() => undefined);
};

export const getContactCode = (): string | null => {
    if (isSsr()) return null;

    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (!stored) return null;

        const {code, timestamp} = JSON.parse(stored);
        if ((Date.now() - timestamp) / (1000 * 60 * 60 * 24) > EXPIRY_DAYS) {
            localStorage.removeItem(STORAGE_KEY);
            return null;
        }

        return normalizeContactCode(code);
    } catch {
        return null;
    }
};

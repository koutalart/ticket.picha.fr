import {isSsr} from "./helpers.ts";
import {getAttributionTrackingContext} from "./attribution";
import {OrderTrackingContext} from "../api/order.client.ts";
import {qrLinkClientPublic} from "../api/qr-link.client.ts";

const STORAGE_KEY = 'picha_qr_link';
const QUERY_PARAM = 'qr';
const COUNTED_PARAM = 'qrc';
const EXPIRY_DAYS = 30;
const SHORT_ID_PATTERN = /^[a-z0-9]{4,32}$/;

let landingVisitRecorded = false;
let rememberedCode: string | null = null;

const normalizeQrLinkCode = (code: string | null | undefined): string | null => {
    const normalized = code?.trim().toLowerCase();
    return normalized && SHORT_ID_PATTERN.test(normalized) ? normalized : null;
};

export const rememberQrLinkCode = (code: string | null | undefined): void => {
    if (isSsr()) return;

    const normalized = normalizeQrLinkCode(code);
    if (!normalized) return;

    rememberedCode = normalized;

    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({code: normalized, timestamp: Date.now()}));
    } catch {
        // Storage can be unavailable (private mode); attribution is best effort.
    }
};

const stripCountedParam = (): void => {
    try {
        const url = new URL(window.location.href);
        url.searchParams.delete(COUNTED_PARAM);
        window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
    } catch {
        // Cosmetic only.
    }
};

const recordLandingVisit = (code: string, alreadyCounted: boolean): void => {
    if (landingVisitRecorded) return;
    landingVisitRecorded = true;

    if (alreadyCounted) {
        stripCountedParam();
        return;
    }

    qrLinkClientPublic.recordScan(code, document.referrer || null).catch(() => undefined);
};

export const captureQrLinkCodeFromUrl = (): void => {
    if (isSsr()) return;

    const params = new URLSearchParams(window.location.search);
    const code = normalizeQrLinkCode(params.get(QUERY_PARAM));
    if (!code) return;

    rememberQrLinkCode(code);
    recordLandingVisit(code, params.has(COUNTED_PARAM));
};

const readStoredQrLinkCode = (): string | null => {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (!stored) return null;

        const {code, timestamp} = JSON.parse(stored);
        if ((Date.now() - timestamp) / (1000 * 60 * 60 * 24) > EXPIRY_DAYS) {
            localStorage.removeItem(STORAGE_KEY);
            return null;
        }

        return normalizeQrLinkCode(typeof code === 'string' ? code : null);
    } catch {
        return null;
    }
};

/**
 * The QR / campaign link of the visit: the link in the current URL first, then the one remembered on this browser.
 * The in-memory copy covers in-app browsers where storage is blocked.
 */
export const getQrLinkCode = (): string | null => {
    if (isSsr()) return null;

    return normalizeQrLinkCode(new URLSearchParams(window.location.search).get(QUERY_PARAM))
        ?? readStoredQrLinkCode()
        ?? rememberedCode;
};

export const getOrderTrackingContext = (): OrderTrackingContext | null => getAttributionTrackingContext();

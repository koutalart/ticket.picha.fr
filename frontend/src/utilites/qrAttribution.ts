import {isSsr} from "./helpers.ts";
import {getConsentState} from "./trackingPixels/consent.ts";
import {OrderTrackingContext} from "../api/order.client.ts";

const STORAGE_KEY = 'picha_qr_link';
const QUERY_PARAM = 'qr';
const EXPIRY_DAYS = 30;
const SHORT_ID_PATTERN = /^[a-z0-9]{4,32}$/;

export const rememberQrLinkCode = (code: string | null | undefined): void => {
    if (isSsr() || !code) return;

    const normalized = code.trim().toLowerCase();
    if (!SHORT_ID_PATTERN.test(normalized)) return;

    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({code: normalized, timestamp: Date.now()}));
    } catch {
        // Storage can be unavailable (private mode); attribution is best effort.
    }
};

export const captureQrLinkCodeFromUrl = (): void => {
    if (isSsr()) return;
    rememberQrLinkCode(new URLSearchParams(window.location.search).get(QUERY_PARAM));
};

export const getQrLinkCode = (): string | null => {
    if (isSsr()) return null;

    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (!stored) return null;

        const {code, timestamp} = JSON.parse(stored);
        if ((Date.now() - timestamp) / (1000 * 60 * 60 * 24) > EXPIRY_DAYS) {
            localStorage.removeItem(STORAGE_KEY);
            return null;
        }

        return typeof code === 'string' ? code : null;
    } catch {
        return null;
    }
};

const readCookie = (name: string): string | null => {
    const match = document.cookie.match(new RegExp(`(?:^|;\\s*)${name}=([^;]*)`));
    return match ? decodeURIComponent(match[1]) : null;
};

export const getOrderTrackingContext = (): OrderTrackingContext | null => {
    if (isSsr() || getConsentState() !== 'granted') return null;

    return {
        consent: true,
        fbp: readCookie('_fbp'),
        fbc: readCookie('_fbc'),
        event_source_url: window.location.href,
    };
};

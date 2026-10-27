import {getConfig} from "./config.ts";

const storageBaseUrl = (apiUrl?: string): string | null => apiUrl ? `${apiUrl.replace(/\/$/, '')}/storage/` : null;

export const rewriteStorageUrls = <T>(data: T, from: string, to: string): T => {
    if (typeof data === 'string') {
        return (data.startsWith(from) ? to + data.slice(from.length) : data) as T;
    }
    if (Array.isArray(data)) {
        return data.map((item) => rewriteStorageUrls(item, from, to)) as T;
    }
    if (data && typeof data === 'object' && Object.getPrototypeOf(data) === Object.prototype) {
        return Object.fromEntries(
            Object.entries(data as Record<string, unknown>).map(([key, value]) => [key, rewriteStorageUrls(value, from, to)])
        ) as T;
    }
    return data;
};

/**
 * On an organizer's own domain the server renders images from that domain (/api/ is proxied there),
 * so data fetched in the browser gets the same URLs: no second connection, no image swapped after a refetch.
 */
export const customDomainStorageRewrite = (): { from: string; to: string } | null => {
    if (typeof window === 'undefined' || !window.__CUSTOM_DOMAIN_ORGANIZER__) {
        return null;
    }
    const from = storageBaseUrl(getConfig('VITE_API_URL_CLIENT'));
    if (!from) {
        return null;
    }
    const to = `${window.location.origin}/api/storage/`;
    return from === to ? null : {from, to};
};

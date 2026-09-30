export interface CustomDomainOrganizer {
    id: number;
    slug: string;
    domain: string;
}

export const CUSTOM_DOMAIN_HEADER = 'x-picha-custom-domain-organizer';

declare global {
    interface Window {
        __CUSTOM_DOMAIN_ORGANIZER__?: CustomDomainOrganizer | null;
    }
}

export const getCustomDomainOrganizer = (request: Request): CustomDomainOrganizer | null => {
    if (typeof window !== 'undefined') {
        return window.__CUSTOM_DOMAIN_ORGANIZER__ ?? null;
    }

    const raw = request.headers.get(CUSTOM_DOMAIN_HEADER);
    if (!raw) {
        return null;
    }

    try {
        return JSON.parse(raw) as CustomDomainOrganizer;
    } catch {
        return null;
    }
};

import {IdParam, OrganizerSettings} from "../../types";
import {getConsentChoice} from "./consent";
import {readCookie} from "../attribution";
import {MetaPageEventName, metaServerEventClientPublic} from "../../api/meta-server-event.client";

/**
 * Server copies (Conversions API) of the Meta pixel events, sharing the pixel's event_id so Meta counts each once.
 * InitiateCheckout and Purchase are sent by the backend with the ids "checkout_<order>" and "order_<order>".
 */

const sentEventIds = new Set<string>();
let pageView: { path: string; id: string } | null = null;

export const newMetaEventId = (prefix: string): string => {
    const random = typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID().replace(/-/g, '')
        : `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 12)}`;
    return `${prefix}_${random}`;
};

/**
 * The PageView event_id of the current page, shared by the pixel and the server event.
 */
export const getPageViewEventId = (): string => {
    const path = typeof window === 'undefined' ? '' : window.location.pathname;
    if (!pageView || pageView.path !== path) {
        pageView = {path, id: newMetaEventId('pv')};
    }
    return pageView.id;
};

export const isMetaServerEventsEnabled = (settings: OrganizerSettings | undefined, consentGranted: boolean): boolean => {
    if (!settings?.meta_conversions_api_configured) return false;
    const hasFacebookPixel = !!settings.tracking_pixels?.some(
        pixel => pixel.provider === 'facebook_pixel' && pixel.enabled && pixel.pixel_id
    );
    return hasFacebookPixel && (consentGranted || settings.meta_capi_send_without_consent === true);
};

export const sendMetaServerEvent = (eventId: IdParam, eventName: MetaPageEventName, metaEventId: string): void => {
    if (typeof window === 'undefined' || sentEventIds.has(metaEventId)) return;
    sentEventIds.add(metaEventId);

    const consent = getConsentChoice()?.marketing === true;
    const fbclid = consent ? new URLSearchParams(window.location.search).get('fbclid') : null;
    const url = consent ? window.location.href : `${window.location.origin}${window.location.pathname}`;

    metaServerEventClientPublic.track(eventId, {
        event_name: eventName,
        meta_event_id: metaEventId,
        consent,
        event_source_url: url.slice(0, 2048),
        fbp: consent ? readCookie('_fbp') : null,
        fbc: consent ? readCookie('_fbc') : null,
        fbclid,
    }).catch(() => undefined);
};

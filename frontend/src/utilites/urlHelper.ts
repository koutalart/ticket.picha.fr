import {Event, IdParam, ImageType, Organizer, Image} from "../types.ts";
import {getConfig} from "./config.ts";

export const eventCheckoutPath = (eventId: IdParam, orderShortId: IdParam, subPage = '') => {
    return `/checkout/${eventId}/${orderShortId}/${subPage}`;
}

export const eventPreviewPath = (eventId: IdParam) => {
    return `/event/${eventId}/preview`;
}

export const eventHomepagePath = (event: Event) => {
    return `/event/${event?.id}/${event?.slug}`;
}

export const organizerHomepagePath = (organizer: Organizer) => {
    return `/events/${organizer?.id}/${organizer?.slug}`;
}

type PublicUrlOwner = Pick<Organizer, 'custom_domain'> | null | undefined;

const platformBaseUrl = () => String(getConfig('VITE_FRONTEND_URL') ?? '').replace(/\/$/, '');

/**
 * Origin of the organizer's own domain (https://mayottela1ere.fr), or null when it sells on the platform.
 */
export const customDomainOrigin = (organizer: PublicUrlOwner): string | null => {
    const domain = organizer?.custom_domain?.trim().toLowerCase().replace(/^https?:\/\//, '').replace(/^www\./, '').replace(/\/.*$/, '');
    return domain ? `https://${domain}` : null;
}

/**
 * Base of every link aimed at participants: the organizer's own domain when it has one, the platform otherwise.
 */
export const publicBaseUrl = (organizer?: PublicUrlOwner): string => {
    return customDomainOrigin(organizer) ?? platformBaseUrl();
}

/**
 * Moves an absolute link to a participant page of the platform onto the organizer's own domain.
 */
export const toParticipantUrl = (url: string, organizer?: PublicUrlOwner): string => {
    const origin = customDomainOrigin(organizer);
    const platform = platformBaseUrl();
    if (!origin || !platform || !url || !url.startsWith(platform)) {
        return url;
    }
    const rest = url.slice(platform.length);
    return rest === '' || /^[/?#]/.test(rest) ? origin + (rest || '/') : url;
}

export const organizerHomepageUrl = (organizer: Organizer) => {
    return publicBaseUrl(organizer) + organizerHomepagePath(organizer);
}

/**
 * Canonical address of the organizer page: the root of its own domain when it has one.
 */
export const organizerCanonicalUrl = (organizer: Organizer) => {
    const origin = customDomainOrigin(organizer);
    return origin ? `${origin}/` : organizerHomepageUrl(organizer);
}

export const eventCheckoutUrl = (eventId: IdParam, orderShortId: IdParam, subPage = '', organizer?: PublicUrlOwner) => {
    return publicBaseUrl(organizer) + eventCheckoutPath(eventId, orderShortId, subPage);
}

export const eventHomepageUrl = (event: Event, organizer?: PublicUrlOwner) => {
    return publicBaseUrl(event?.organizer ?? organizer) + eventHomepagePath(event);
}

export const eventShareImage = (event: Event): Image | undefined => {
    return event?.images?.find((image) => image.type === 'EVENT_SHARE_IMAGE')
        ?? event?.images?.find((image) => image.type === 'EVENT_COVER');
}

export const eventShareImageUrl = (event: Event) => {
    return eventShareImage(event)?.url;
}

export const eventCoverImageUrl = (event: Event) => {
    return event?.images?.find((image) => image.type === 'EVENT_COVER')?.url;
}

export const eventCoverImage = (event: Event): Image | undefined => {
    return event?.images?.find((image) => image.type === 'EVENT_COVER');
}

export const imageUrl = (imageType: ImageType, images?: Image[], fallbackUrl?: string) => {
    if (!images || images.length === 0) {
        return fallbackUrl || getConfig('VITE_DEFAULT_IMAGE_URL');
    }

    const image = images.find((img) => img.type === imageType);
    if (image) {
        return image.url;
    }
    return fallbackUrl || getConfig('VITE_DEFAULT_IMAGE_URL');
}

export const organizerPreviewPath = (organizerId: IdParam) => {
    return `/organizer/${organizerId}/preview`;
}

const apiBaseUrl = () => String(getConfig('VITE_API_URL_CLIENT') ?? '').replace(/\/$/, '');

export const attendeeTicketImageUrl = (eventId: IdParam, attendeeShortId: string, version?: string) => {
    const query = version ? `?v=${encodeURIComponent(version)}` : '';
    return `${apiBaseUrl()}/public/events/${eventId}/attendees/${attendeeShortId}/ticket.png${query}`;
};

export const ticketPreviewImageUrl = (eventId: IdParam, version?: string) => {
    const query = version ? `?v=${encodeURIComponent(version)}` : '';
    return `${apiBaseUrl()}/events/${eventId}/ticket-preview.png${query}`;
};

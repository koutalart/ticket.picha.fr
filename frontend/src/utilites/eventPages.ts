import {t} from "@lingui/macro";
import {Event, EventArtist, EventPageSlug} from "../types.ts";
import {eventHomepagePath, publicBaseUrl} from "./urlHelper.ts";

export const EVENT_PAGE_SLUGS: EventPageSlug[] = ['artistes', 'infos-pratiques', 'faq'];

export const eventPageLabel = (page: EventPageSlug): string => {
    switch (page) {
        case 'artistes':
            return t({message: 'Artists', context: 'event dedicated page'});
        case 'infos-pratiques':
            return t({message: 'Practical information', context: 'event dedicated page'});
        case 'faq':
            return t({message: 'FAQ', context: 'event dedicated page'});
    }
};

export const eventDedicatedPagePath = (event: Event, page: EventPageSlug) => `${eventHomepagePath(event)}/${page}`;

export const eventDedicatedPageUrl = (event: Event, page: EventPageSlug) =>
    publicBaseUrl(event?.organizer) + eventDedicatedPagePath(event, page);

export const eventRegistrationPath = (event: Event) => `${eventHomepagePath(event)}#tickets`;

export const slugify = (value: string) => value
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');

export const artistAnchor = (artist: EventArtist) => artist.slug || slugify(artist.name) || 'artiste';

export const htmlToText = (html?: string | null) => (html || '')
    .replace(/<[^>]*>/g, ' ')
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&quot;/g, '"')
    .replace(/&#0?39;|&apos;/g, "'")
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/\s+/g, ' ')
    .trim();

export const truncateText = (value: string, maxLength = 160) => {
    if (value.length <= maxLength) {
        return value;
    }
    const cut = value.slice(0, maxLength - 1);
    const lastSpace = cut.lastIndexOf(' ');
    return `${(lastSpace > 80 ? cut.slice(0, lastSpace) : cut).trim()}...`;
};

export const jsonLdScript = (data: unknown) => JSON.stringify(data).replace(/</g, '\\u003c');

import {QueryClient} from "@tanstack/react-query";
import {CustomerAddressForm, normalizeCustomerAddressForm} from "../locales.ts";
import {Event, IdParam, Organizer} from "../types.ts";
import {GET_EVENT_PUBLIC_QUERY_KEY, getEventPublicQuery} from "../queries/useGetEventPublic.ts";
import {GET_ORGANIZER_PUBLIC_QUERY_KEY, getOrganizerPublicQuery} from "../queries/useGetOrganizerPublic.ts";
import {ORGANIZER_SITE_PAGES} from "../routeLoaders/organizerSitePageLoader.ts";

/**
 * Customer pages that belong to a single event: event page, embedded widget, checkout,
 * order and ticket pages. The organizer's tone (vous / tu) applies to these pages only.
 */
const CUSTOMER_EVENT_PATH = /^\/(?:event|e|widget|checkout|order|product|venue)\/(\d+)(?:\/|$)/;

const IGNORED_PATHS = /^\/event\/\d+\/preview(?:\/|$)/;

/**
 * The organizer homepage and its site pages on the platform: /events/{id}/{slug}[/past-events|/a-propos...].
 */
const ORGANIZER_PATH = /^\/events\/(\d+)(?:\/|$)/;

export const eventIdFromCustomerPath = (pathname: string): string | null => {
    if (IGNORED_PATHS.test(pathname)) {
        return null;
    }

    return pathname.match(CUSTOMER_EVENT_PATH)?.[1] ?? null;
};

/**
 * On an organizer's own domain, the root and the site pages (/a-propos, /evenements...) belong to that organizer.
 */
export const organizerIdFromCustomerPath = (
    pathname: string,
    customDomainOrganizerId?: IdParam | null,
): string | null => {
    const platformMatch = pathname.match(ORGANIZER_PATH)?.[1];
    if (platformMatch) {
        return platformMatch;
    }

    if (!customDomainOrganizerId) {
        return null;
    }

    const page = pathname.replace(/\/+$/, '').replace(/^\//, '');
    if (page === '' || (ORGANIZER_SITE_PAGES as readonly string[]).includes(page)) {
        return String(customDomainOrganizerId);
    }

    return null;
};

export const customerAddressFormFromEvent = (event?: Event | null): CustomerAddressForm =>
    normalizeCustomerAddressForm(event?.organizer?.settings?.customer_address_form);

export const customerAddressFormFromOrganizer = (organizer?: Organizer | null): CustomerAddressForm =>
    normalizeCustomerAddressForm(organizer?.settings?.customer_address_form);

const findCachedEvent = (queryClient: QueryClient, eventId: string): Event | undefined => {
    return queryClient
        .getQueriesData<Event>({queryKey: [GET_EVENT_PUBLIC_QUERY_KEY]})
        .map(([, data]) => data)
        .find((event) => event && String(event.id) === eventId);
};

const findCachedOrganizer = (queryClient: QueryClient, organizerId: string): Organizer | undefined => {
    return queryClient
        .getQueriesData<Organizer>({queryKey: [GET_ORGANIZER_PUBLIC_QUERY_KEY]})
        .map(([, data]) => data)
        .find((organizer) => organizer && String(organizer.id) === organizerId);
};

const resolveForOrganizer = async (queryClient: QueryClient, organizerId: string): Promise<CustomerAddressForm> => {
    const cached = findCachedOrganizer(queryClient, organizerId);
    if (cached) {
        return customerAddressFormFromOrganizer(cached);
    }

    try {
        const organizer = await queryClient.fetchQuery(getOrganizerPublicQuery(organizerId));
        return customerAddressFormFromOrganizer(organizer);
    } catch {
        return 'vous';
    }
};

export const resolveCustomerAddressForm = async (
    pathname: string,
    queryClient: QueryClient,
    customDomainOrganizerId?: IdParam | null,
): Promise<CustomerAddressForm> => {
    const organizerId = organizerIdFromCustomerPath(pathname, customDomainOrganizerId);
    if (organizerId) {
        return resolveForOrganizer(queryClient, organizerId);
    }

    const eventId = eventIdFromCustomerPath(pathname);
    if (!eventId) {
        return 'vous';
    }

    const cached = findCachedEvent(queryClient, eventId);
    if (cached) {
        return customerAddressFormFromEvent(cached);
    }

    try {
        const event = await queryClient.fetchQuery(getEventPublicQuery(eventId, null, false));
        return customerAddressFormFromEvent(event);
    } catch {
        return 'vous';
    }
};

import {QueryClient} from "@tanstack/react-query";
import {CustomerAddressForm, normalizeCustomerAddressForm} from "../locales.ts";
import {Event} from "../types.ts";
import {GET_EVENT_PUBLIC_QUERY_KEY, getEventPublicQuery} from "../queries/useGetEventPublic.ts";

/**
 * Customer pages that belong to a single event: event page, embedded widget, checkout,
 * order and ticket pages. The organizer's tone (vous / tu) applies to these pages only.
 */
const CUSTOMER_EVENT_PATH = /^\/(?:event|e|widget|checkout|order|product|venue)\/(\d+)(?:\/|$)/;

const IGNORED_PATHS = /^\/event\/\d+\/preview(?:\/|$)/;

export const eventIdFromCustomerPath = (pathname: string): string | null => {
    if (IGNORED_PATHS.test(pathname)) {
        return null;
    }

    return pathname.match(CUSTOMER_EVENT_PATH)?.[1] ?? null;
};

export const customerAddressFormFromEvent = (event?: Event | null): CustomerAddressForm =>
    normalizeCustomerAddressForm(event?.organizer?.settings?.customer_address_form);

const findCachedEvent = (queryClient: QueryClient, eventId: string): Event | undefined => {
    return queryClient
        .getQueriesData<Event>({queryKey: [GET_EVENT_PUBLIC_QUERY_KEY]})
        .map(([, data]) => data)
        .find((event) => event && String(event.id) === eventId);
};

export const resolveCustomerAddressForm = async (
    pathname: string,
    queryClient: QueryClient,
): Promise<CustomerAddressForm> => {
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

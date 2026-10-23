import {LoaderFunctionArgs, redirect} from "react-router";
import {getEventPublicQuery} from "../queries/useGetEventPublic.ts";
import {getEventPagesPublicQuery} from "../queries/useGetEventPagesPublic.ts";
import {getQueryClient} from "../utilites/ssrQueryClient.ts";
import {EventPageSlug} from "../types.ts";

const notFound = () => new Response('Not Found', {status: 404});

export const eventDedicatedPageRouteLoader = (page: EventPageSlug) => async ({params, request}: LoaderFunctionArgs) => {
    try {
        const queryClient = getQueryClient();
        const [event, pages] = await Promise.all([
            queryClient.fetchQuery(getEventPublicQuery(params.eventId, null, false)),
            queryClient.fetchQuery(getEventPagesPublicQuery(params.eventId)),
        ]);

        if (!event || !pages?.available_pages?.includes(page)) {
            throw notFound();
        }

        if (event.slug && params.eventSlug !== event.slug) {
            const search = new URL(request.url).search;
            throw redirect(`/event/${event.id}/${event.slug}/${page}${search}`);
        }

        return {event, pages, page};
    } catch (error: any) {
        if (error instanceof Response) {
            throw error;
        }

        if (error?.response?.status === 404) {
            throw notFound();
        }

        throw error;
    }
};

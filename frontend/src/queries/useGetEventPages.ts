import {useQuery} from "@tanstack/react-query";
import {EventPages, IdParam} from "../types.ts";
import {eventPagesClient} from "../api/event-pages.client.ts";

export const GET_EVENT_PAGES_QUERY_KEY = 'getEventPages';

export const useGetEventPages = (eventId: IdParam) => {
    return useQuery<EventPages>({
        queryKey: [GET_EVENT_PAGES_QUERY_KEY, eventId],
        queryFn: async () => {
            const {data} = await eventPagesClient.get(eventId);
            return data;
        },
        enabled: !!eventId,
    });
};

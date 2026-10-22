import {useQuery} from "@tanstack/react-query";
import {EventPages, IdParam} from "../types.ts";
import {eventPagesClientPublic} from "../api/event-pages.client.ts";

export const GET_EVENT_PAGES_PUBLIC_QUERY_KEY = 'getEventPagesPublic';

export const getEventPagesPublicQuery = (eventId: IdParam) => ({
    queryKey: [GET_EVENT_PAGES_PUBLIC_QUERY_KEY, eventId] as const,
    queryFn: async (): Promise<EventPages> => {
        const {data} = await eventPagesClientPublic.get(eventId);
        return data;
    },
    refetchOnWindowFocus: false,
    retry: false,
});

export const useGetEventPagesPublic = (eventId: IdParam) => {
    return useQuery<EventPages>(getEventPagesPublicQuery(eventId));
};

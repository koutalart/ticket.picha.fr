import {useQuery} from "@tanstack/react-query";
import {eventsClientPublic} from "../api/event.client.ts";
import {Event, IdParam} from "../types.ts";

export const GET_PUBLIC_EVENT_QUERY_KEY = 'getPublicEvent';

export const useGetPublicEvent = (eventId: IdParam, enabled: boolean = true) => {
    return useQuery<Event>({
        queryKey: [GET_PUBLIC_EVENT_QUERY_KEY, eventId],
        queryFn: async () => {
            const {data} = await eventsClientPublic.findByID(eventId, null);
            return data;
        },
        enabled: enabled && !!eventId,
        staleTime: 30_000,
    });
};

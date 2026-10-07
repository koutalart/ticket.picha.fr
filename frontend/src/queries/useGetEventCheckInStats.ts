import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {eventsClient} from "../api/event.client.ts";

export const GET_EVENT_CHECK_IN_STATS_QUERY_KEY = 'getEventCheckInStats';

export const useGetEventCheckInStats = (eventId: IdParam, enabled: boolean = true) => {
    return useQuery({
        queryKey: [GET_EVENT_CHECK_IN_STATS_QUERY_KEY, eventId],
        queryFn: async () => {
            const {data} = await eventsClient.getEventCheckInStats(eventId);
            return data;
        },
        enabled,
    });
};

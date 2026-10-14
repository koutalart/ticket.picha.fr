import {useQuery} from "@tanstack/react-query";
import {eventAssistantClient} from "../api/event-assistant.client.ts";

export const GET_EVENT_ASSISTANT_STATUS_QUERY_KEY = 'getEventAssistantStatus';

export const useGetEventAssistantStatus = () => {
    return useQuery({
        queryKey: [GET_EVENT_ASSISTANT_STATUS_QUERY_KEY],

        queryFn: async () => {
            const {data} = await eventAssistantClient.status();
            return data;
        },
        staleTime: 5 * 60 * 1000,
    });
};

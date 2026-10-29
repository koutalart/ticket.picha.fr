import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {liveChatPublicClient} from "../api/live-chat.client.ts";

export const GET_LIVE_CHAT_CONFIG_QUERY_KEY = 'getLiveChatConfig';

// On an event page the greeting is about that event.
export const useGetLiveChatConfig = (organizerId: IdParam | undefined, eventId?: IdParam | null) => {
    return useQuery({
        queryKey: [GET_LIVE_CHAT_CONFIG_QUERY_KEY, organizerId, eventId ?? null],
        queryFn: async () => {
            const {data} = await liveChatPublicClient.config(organizerId!, eventId);
            return data;
        },
        enabled: !!organizerId,
        staleTime: 5 * 60 * 1000,
        retry: false,
    });
};

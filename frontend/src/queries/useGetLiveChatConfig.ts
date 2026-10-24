import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {liveChatPublicClient} from "../api/live-chat.client.ts";

export const GET_LIVE_CHAT_CONFIG_QUERY_KEY = 'getLiveChatConfig';

export const useGetLiveChatConfig = (organizerId: IdParam | undefined) => {
    return useQuery({
        queryKey: [GET_LIVE_CHAT_CONFIG_QUERY_KEY, organizerId],
        queryFn: async () => {
            const {data} = await liveChatPublicClient.config(organizerId!);
            return data;
        },
        enabled: !!organizerId,
        staleTime: 5 * 60 * 1000,
        retry: false,
    });
};

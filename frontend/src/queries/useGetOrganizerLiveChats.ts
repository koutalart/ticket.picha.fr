import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {liveChatClient} from "../api/live-chat.client.ts";

export const GET_ORGANIZER_LIVE_CHATS_QUERY_KEY = 'getOrganizerLiveChats';

export const useGetOrganizerLiveChats = (organizerId: IdParam | undefined, page = 1) => {
    return useQuery({
        queryKey: [GET_ORGANIZER_LIVE_CHATS_QUERY_KEY, organizerId, page],
        queryFn: async () => liveChatClient.list(organizerId!, page),
        enabled: !!organizerId,
        refetchInterval: 30000,
    });
};

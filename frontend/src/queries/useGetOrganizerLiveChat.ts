import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {liveChatClient} from "../api/live-chat.client.ts";

export const GET_ORGANIZER_LIVE_CHAT_QUERY_KEY = 'getOrganizerLiveChat';

export const useGetOrganizerLiveChat = (organizerId: IdParam | undefined, sessionId: IdParam | null) => {
    return useQuery({
        queryKey: [GET_ORGANIZER_LIVE_CHAT_QUERY_KEY, organizerId, sessionId],
        queryFn: async () => {
            const {data} = await liveChatClient.get(organizerId!, sessionId!);
            return data;
        },
        enabled: !!organizerId && !!sessionId,
    });
};

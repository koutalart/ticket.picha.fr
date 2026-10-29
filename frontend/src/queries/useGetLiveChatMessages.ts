import {QueryClient} from "@tanstack/react-query";
import {LiveChatTurn} from "../types.ts";
import {liveChatPublicClient} from "../api/live-chat.client.ts";

export const GET_LIVE_CHAT_MESSAGES_QUERY_KEY = 'getLiveChatMessages';

const STALE_TIME = 60 * 1000;

export const liveChatMessagesQueryKey = (sessionId: string) => [GET_LIVE_CHAT_MESSAGES_QUERY_KEY, sessionId];

export const getCachedLiveChatMessages = (queryClient: QueryClient, sessionId: string) =>
    queryClient.getQueryData<LiveChatTurn>(liveChatMessagesQueryKey(sessionId));

/**
 * The conversation of a stored chat session: served from the cache while it is fresh, so that reopening the chat
 * shows it at once without a network round trip.
 */
export const fetchLiveChatMessages = (queryClient: QueryClient, sessionId: string) =>
    queryClient.fetchQuery({
        queryKey: liveChatMessagesQueryKey(sessionId),
        queryFn: async () => {
            const {data} = await liveChatPublicClient.messages(sessionId);
            return data;
        },
        staleTime: STALE_TIME,
        retry: false,
    });

export const cacheLiveChatMessages = (queryClient: QueryClient, turn: LiveChatTurn) => {
    queryClient.setQueryData(liveChatMessagesQueryKey(turn.session_id), turn);
};

export const forgetLiveChatMessages = (queryClient: QueryClient, sessionId: string) => {
    queryClient.removeQueries({queryKey: liveChatMessagesQueryKey(sessionId)});
};

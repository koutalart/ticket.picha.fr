import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {smsClient} from "../api/sms.client.ts";
import {SMS_POLL_INTERVAL_MS} from "./useGetSmsConversations.ts";

export const GET_SMS_CONVERSATION_QUERY_KEY = 'getSmsConversation';

export const useGetSmsConversation = (organizerId: IdParam, conversationId: IdParam) => {
    return useQuery({
        queryKey: [GET_SMS_CONVERSATION_QUERY_KEY, organizerId, conversationId],
        queryFn: async () => {
            const {data} = await smsClient.conversation(organizerId, conversationId);
            return data;
        },
        enabled: !!organizerId && !!conversationId,
        refetchInterval: SMS_POLL_INTERVAL_MS,
    });
};

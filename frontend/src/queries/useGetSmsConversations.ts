import {keepPreviousData, useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {smsClient, SmsConversationsParams} from "../api/sms.client.ts";

export const GET_SMS_CONVERSATIONS_QUERY_KEY = 'getSmsConversations';
export const SMS_POLL_INTERVAL_MS = 15000;

export const useGetSmsConversations = (organizerId: IdParam, params: SmsConversationsParams) => {
    return useQuery({
        queryKey: [GET_SMS_CONVERSATIONS_QUERY_KEY, organizerId, params],
        queryFn: () => smsClient.conversations(organizerId, params),
        enabled: !!organizerId,
        refetchInterval: SMS_POLL_INTERVAL_MS,
        placeholderData: keepPreviousData,
    });
};

import {useInfiniteQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {smsClient, SmsConversationsParams} from "../api/sms.client.ts";

export const GET_SMS_CONVERSATIONS_QUERY_KEY = 'getSmsConversations';
export const SMS_POLL_INTERVAL_MS = 15000;

export const useGetSmsConversations = (organizerId: IdParam, params: Omit<SmsConversationsParams, 'page'>) => {
    return useInfiniteQuery({
        queryKey: [GET_SMS_CONVERSATIONS_QUERY_KEY, organizerId, params],
        queryFn: ({pageParam}) => smsClient.conversations(organizerId, {...params, page: pageParam}),
        initialPageParam: 1,
        getNextPageParam: (lastPage) => lastPage.meta.current_page < lastPage.meta.last_page
            ? lastPage.meta.current_page + 1
            : undefined,
        enabled: !!organizerId,
        refetchInterval: SMS_POLL_INTERVAL_MS,
    });
};

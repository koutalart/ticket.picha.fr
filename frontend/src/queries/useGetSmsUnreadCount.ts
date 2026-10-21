import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {smsClient} from "../api/sms.client.ts";

export const GET_SMS_UNREAD_COUNT_QUERY_KEY = 'getSmsUnreadCount';

export const useGetSmsUnreadCount = (organizerId: IdParam) => {
    return useQuery({
        queryKey: [GET_SMS_UNREAD_COUNT_QUERY_KEY, organizerId],
        queryFn: async () => {
            const {data} = await smsClient.unreadCount(organizerId);
            return data.unread_conversations;
        },
        enabled: !!organizerId,
        refetchInterval: 60000,
    });
};

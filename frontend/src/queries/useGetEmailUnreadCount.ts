import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {emailInboxClient} from "../api/email-inbox.client.ts";

export const GET_EMAIL_UNREAD_COUNT_QUERY_KEY = 'getEmailUnreadCount';

export const useGetEmailUnreadCount = (organizerId: IdParam) => {
    return useQuery({
        queryKey: [GET_EMAIL_UNREAD_COUNT_QUERY_KEY, organizerId],
        queryFn: async () => {
            const {data} = await emailInboxClient.unreadCount(organizerId);
            return data.unread_threads;
        },
        enabled: !!organizerId,
        refetchInterval: 60000,
    });
};

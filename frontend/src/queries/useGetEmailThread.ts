import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {emailInboxClient} from "../api/email-inbox.client.ts";
import {EMAIL_POLL_INTERVAL_MS} from "./useGetEmailThreads.ts";

export const GET_EMAIL_THREAD_QUERY_KEY = 'getEmailThread';

export const useGetEmailThread = (organizerId: IdParam, threadId: IdParam) => {
    return useQuery({
        queryKey: [GET_EMAIL_THREAD_QUERY_KEY, organizerId, threadId],
        queryFn: async () => {
            const {data} = await emailInboxClient.thread(organizerId, threadId);
            return data;
        },
        enabled: !!organizerId && !!threadId,
        refetchInterval: EMAIL_POLL_INTERVAL_MS,
    });
};

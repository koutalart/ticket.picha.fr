import {useInfiniteQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {emailInboxClient, EmailThreadsParams} from "../api/email-inbox.client.ts";

export const GET_EMAIL_THREADS_QUERY_KEY = 'getEmailThreads';
export const EMAIL_POLL_INTERVAL_MS = 30000;

export const useGetEmailThreads = (organizerId: IdParam, params: Omit<EmailThreadsParams, 'page'>, enabled = true) => {
    return useInfiniteQuery({
        queryKey: [GET_EMAIL_THREADS_QUERY_KEY, organizerId, params],
        queryFn: ({pageParam}) => emailInboxClient.threads(organizerId, {...params, page: pageParam}),
        initialPageParam: 1,
        getNextPageParam: (lastPage) => lastPage.meta.current_page < lastPage.meta.last_page
            ? lastPage.meta.current_page + 1
            : undefined,
        enabled: !!organizerId && enabled,
        refetchInterval: EMAIL_POLL_INTERVAL_MS,
    });
};

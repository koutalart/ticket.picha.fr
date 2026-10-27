import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {emailInboxClient} from "../api/email-inbox.client.ts";
import {GET_EMAIL_THREAD_QUERY_KEY} from "../queries/useGetEmailThread.ts";
import {GET_EMAIL_THREADS_QUERY_KEY} from "../queries/useGetEmailThreads.ts";

export const useReplyEmailThread = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, threadId, body, subject}: {
            organizerId: IdParam,
            threadId: IdParam,
            body: string,
            subject?: string,
        }) => emailInboxClient.reply(organizerId, threadId, body, subject),

        onSettled: (_, __, variables) => Promise.all([
            queryClient.invalidateQueries({queryKey: [GET_EMAIL_THREAD_QUERY_KEY, variables.organizerId, variables.threadId]}),
            queryClient.invalidateQueries({queryKey: [GET_EMAIL_THREADS_QUERY_KEY, variables.organizerId]}),
        ]),
    });
};

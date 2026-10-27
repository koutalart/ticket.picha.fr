import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {emailInboxClient, NewEmailRequest} from "../api/email-inbox.client.ts";
import {GET_EMAIL_THREADS_QUERY_KEY} from "../queries/useGetEmailThreads.ts";

export const useSendNewEmail = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, request}: {
            organizerId: IdParam,
            request: NewEmailRequest,
        }) => emailInboxClient.send(organizerId, request),

        onSettled: (_, __, variables) => queryClient.invalidateQueries({
            queryKey: [GET_EMAIL_THREADS_QUERY_KEY, variables.organizerId],
        }),
    });
};

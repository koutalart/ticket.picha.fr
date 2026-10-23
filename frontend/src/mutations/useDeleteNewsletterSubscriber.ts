import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {newsletterSubscriberClient} from "../api/organizer.client.ts";
import {GET_NEWSLETTER_SUBSCRIBERS_QUERY_KEY} from "../queries/useGetNewsletterSubscribers.ts";

export const useDeleteNewsletterSubscriber = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, subscriberId}: { organizerId: IdParam, subscriberId: IdParam }) =>
            newsletterSubscriberClient.delete(organizerId, subscriberId),

        onSuccess: (_, variables) => queryClient.invalidateQueries({
            queryKey: [GET_NEWSLETTER_SUBSCRIBERS_QUERY_KEY, variables.organizerId],
        }),
    });
};

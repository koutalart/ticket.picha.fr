import {useMutation, useQueryClient} from "@tanstack/react-query";
import {EventPages, IdParam} from "../types.ts";
import {eventPagesClient} from "../api/event-pages.client.ts";
import {GET_EVENT_PAGES_QUERY_KEY} from "../queries/useGetEventPages.ts";
import {GET_EVENT_PAGES_PUBLIC_QUERY_KEY} from "../queries/useGetEventPagesPublic.ts";
import {GET_EVENT_IMAGES_QUERY_KEY} from "../queries/useGetEventImages.ts";

export const useUpdateEventPages = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({eventId, pages}: { eventId: IdParam, pages: EventPages }) =>
            eventPagesClient.update(eventId, pages),

        onSuccess: (response, variables) => {
            queryClient.setQueryData([GET_EVENT_PAGES_QUERY_KEY, variables.eventId], response.data);

            return Promise.all([
                queryClient.invalidateQueries({queryKey: [GET_EVENT_PAGES_PUBLIC_QUERY_KEY, variables.eventId]}),
                queryClient.invalidateQueries({queryKey: [GET_EVENT_IMAGES_QUERY_KEY, variables.eventId]}),
            ]);
        },
    });
};

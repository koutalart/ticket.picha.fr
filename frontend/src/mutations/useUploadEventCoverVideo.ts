import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {eventsClient} from "../api/event.client.ts";
import {GET_EVENT_IMAGES_QUERY_KEY} from "../queries/useGetEventImages.ts";
import {GET_EVENT_PUBLIC_QUERY_KEY} from "../queries/useGetEventPublic.ts";

export const useUploadEventCoverVideo = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({eventId, video}: {
            eventId: IdParam,
            video: File,
        }) => eventsClient.uploadEventCoverVideo(eventId, video),

        onSuccess: (_, variables) => Promise.all([
            queryClient.invalidateQueries({queryKey: [GET_EVENT_IMAGES_QUERY_KEY, variables.eventId]}),
            queryClient.invalidateQueries({queryKey: [GET_EVENT_PUBLIC_QUERY_KEY, variables.eventId]}),
        ]),
    });
};

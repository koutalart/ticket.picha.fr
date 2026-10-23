import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {organizerClient} from "../api/organizer.client.ts";
import {GET_ORGANIZER_QUERY_KEY} from "../queries/useGetOrganizer.ts";

export const useUploadOrganizerCoverVideo = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, video, mobile = false}: {
            organizerId: IdParam,
            video: File,
            mobile?: boolean,
        }) => organizerClient.uploadCoverVideo(organizerId, video, mobile),

        onSuccess: (_, variables) => queryClient.invalidateQueries({
            queryKey: [GET_ORGANIZER_QUERY_KEY, variables.organizerId],
        }),
    });
};

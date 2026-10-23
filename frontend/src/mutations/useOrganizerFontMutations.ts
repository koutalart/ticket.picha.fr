import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {organizerClient} from "../api/organizer.client.ts";
import {GET_ORGANIZER_FONTS_QUERY_KEY} from "../queries/useGetOrganizerFonts.ts";
import {GET_ORGANIZER_PUBLIC_QUERY_KEY} from "../queries/useGetOrganizerPublic.ts";

const useInvalidateOrganizerFonts = () => {
    const queryClient = useQueryClient();

    return (organizerId: IdParam) => Promise.all([
        queryClient.invalidateQueries({queryKey: [GET_ORGANIZER_FONTS_QUERY_KEY, organizerId]}),
        queryClient.invalidateQueries({queryKey: [GET_ORGANIZER_PUBLIC_QUERY_KEY, organizerId]}),
    ]);
};

export const useUploadOrganizerFont = () => {
    const invalidate = useInvalidateOrganizerFonts();

    return useMutation({
        mutationFn: ({organizerId, file, weight, familyName}: {
            organizerId: IdParam,
            file: File,
            weight: number,
            familyName: string,
        }) => organizerClient.uploadFont(organizerId, file, weight, familyName),

        onSuccess: (_, variables) => invalidate(variables.organizerId),
    });
};

export const useUpdateOrganizerFontFamily = () => {
    const invalidate = useInvalidateOrganizerFonts();

    return useMutation({
        mutationFn: ({organizerId, familyName}: {
            organizerId: IdParam,
            familyName: string,
        }) => organizerClient.updateFontFamily(organizerId, familyName),

        onSuccess: (_, variables) => invalidate(variables.organizerId),
    });
};

export const useDeleteOrganizerFont = () => {
    const invalidate = useInvalidateOrganizerFonts();

    return useMutation({
        mutationFn: ({organizerId, fontId}: {
            organizerId: IdParam,
            fontId: IdParam,
        }) => organizerClient.deleteFont(organizerId, fontId),

        onSuccess: (_, variables) => invalidate(variables.organizerId),
    });
};

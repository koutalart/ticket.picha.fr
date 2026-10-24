import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {socialClient} from "../api/social.client.ts";
import {GET_SOCIAL_CONNECTION_QUERY_KEY} from "../queries/useGetSocialConnection.ts";

export const useConnectMetaPage = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, selectionKey, pageId}: { organizerId: IdParam, selectionKey: string, pageId: string }) =>
            socialClient.connect(organizerId, selectionKey, pageId),

        onSuccess: (_, variables) => queryClient.invalidateQueries({queryKey: [GET_SOCIAL_CONNECTION_QUERY_KEY, variables.organizerId]}),
    });
};

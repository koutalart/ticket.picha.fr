import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {socialClient} from "../api/social.client.ts";
import {GET_SOCIAL_COMMENTS_QUERY_KEY} from "../queries/useGetSocialComments.ts";

export const useReplyToSocialComment = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, commentId, message}: { organizerId: IdParam, commentId: IdParam, message: string }) =>
            socialClient.reply(organizerId, commentId, message),

        onSuccess: (_, variables) => queryClient.invalidateQueries({queryKey: [GET_SOCIAL_COMMENTS_QUERY_KEY, variables.organizerId]}),
    });
};

import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {socialClient} from "../api/social.client.ts";
import {GET_SOCIAL_POSTS_QUERY_KEY} from "../queries/useGetSocialPosts.ts";

export const useDeleteSocialPost = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, postId}: { organizerId: IdParam, postId: IdParam }) =>
            socialClient.deletePost(organizerId, postId),

        onSuccess: (_, variables) => queryClient.invalidateQueries({queryKey: [GET_SOCIAL_POSTS_QUERY_KEY, variables.organizerId]}),
    });
};

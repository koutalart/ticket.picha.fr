import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {socialClient} from "../api/social.client.ts";
import {GET_SOCIAL_POSTS_QUERY_KEY} from "../queries/useGetSocialPosts.ts";
import {GET_SOCIAL_FEED_QUERY_KEY} from "../queries/useGetSocialFeed.ts";

export const useScheduleSocialPost = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, postId}: { organizerId: IdParam, postId: IdParam }) =>
            socialClient.schedulePost(organizerId, postId),

        onSuccess: (_, variables) => Promise.all([
            queryClient.invalidateQueries({queryKey: [GET_SOCIAL_POSTS_QUERY_KEY, variables.organizerId]}),
            queryClient.invalidateQueries({queryKey: [GET_SOCIAL_FEED_QUERY_KEY, variables.organizerId]}),
        ]),
    });
};

import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {CreateSocialPostRequest, socialClient} from "../api/social.client.ts";
import {GET_SOCIAL_POSTS_QUERY_KEY} from "../queries/useGetSocialPosts.ts";
import {GET_SOCIAL_FEED_QUERY_KEY} from "../queries/useGetSocialFeed.ts";

export const useCreateSocialPosts = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, post}: { organizerId: IdParam, post: CreateSocialPostRequest }) =>
            socialClient.createPosts(organizerId, post),

        onSuccess: (_, variables) => Promise.all([
            queryClient.invalidateQueries({queryKey: [GET_SOCIAL_POSTS_QUERY_KEY, variables.organizerId]}),
            queryClient.invalidateQueries({queryKey: [GET_SOCIAL_FEED_QUERY_KEY, variables.organizerId]}),
        ]),
    });
};

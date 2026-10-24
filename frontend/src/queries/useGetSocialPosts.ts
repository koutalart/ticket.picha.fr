import {keepPreviousData, useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {socialClient, SocialPostsParams} from "../api/social.client.ts";

export const GET_SOCIAL_POSTS_QUERY_KEY = 'getSocialPosts';

export const useGetSocialPosts = (organizerId: IdParam, params: SocialPostsParams) => {
    return useQuery({
        queryKey: [GET_SOCIAL_POSTS_QUERY_KEY, organizerId, params],
        queryFn: () => socialClient.posts(organizerId, params),
        enabled: !!organizerId,
        placeholderData: keepPreviousData,
        refetchInterval: 30000,
    });
};

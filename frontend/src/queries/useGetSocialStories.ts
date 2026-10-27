import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {socialClient} from "../api/social.client.ts";

export const GET_SOCIAL_STORIES_QUERY_KEY = 'getSocialStories';

export const useGetSocialStories = (organizerId: IdParam, enabled = true) => {
    return useQuery({
        queryKey: [GET_SOCIAL_STORIES_QUERY_KEY, organizerId],
        queryFn: () => socialClient.stories(organizerId),
        enabled: !!organizerId && enabled,
        refetchInterval: 120000,
    });
};

import {keepPreviousData, useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {socialClient} from "../api/social.client.ts";

export const GET_SOCIAL_FEED_QUERY_KEY = 'getSocialFeed';

export const useGetSocialFeed = (organizerId: IdParam, limit: number, enabled = true) => {
    return useQuery({
        queryKey: [GET_SOCIAL_FEED_QUERY_KEY, organizerId, limit],
        queryFn: () => socialClient.feed(organizerId, limit),
        enabled: !!organizerId && enabled,
        placeholderData: keepPreviousData,
        refetchInterval: 45000,
    });
};

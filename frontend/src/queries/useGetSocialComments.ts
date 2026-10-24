import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {socialClient} from "../api/social.client.ts";

export const GET_SOCIAL_COMMENTS_QUERY_KEY = 'getSocialComments';

export const useGetSocialComments = (organizerId: IdParam, feedItemId: IdParam | null) => {
    return useQuery({
        queryKey: [GET_SOCIAL_COMMENTS_QUERY_KEY, organizerId, feedItemId],
        queryFn: async () => (await socialClient.comments(organizerId, feedItemId!)).data,
        enabled: !!organizerId && !!feedItemId,
        refetchInterval: 30000,
    });
};

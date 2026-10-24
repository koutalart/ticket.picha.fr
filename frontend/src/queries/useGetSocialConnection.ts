import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {socialClient} from "../api/social.client.ts";

export const GET_SOCIAL_CONNECTION_QUERY_KEY = 'getSocialConnection';

export const useGetSocialConnection = (organizerId: IdParam) => {
    return useQuery({
        queryKey: [GET_SOCIAL_CONNECTION_QUERY_KEY, organizerId],
        queryFn: async () => (await socialClient.connection(organizerId)).data,
        enabled: !!organizerId,
    });
};

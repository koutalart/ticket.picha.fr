import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {socialClient} from "../api/social.client.ts";

export const GET_PENDING_META_PAGES_QUERY_KEY = 'getPendingMetaPages';

export const useGetPendingMetaPages = (organizerId: IdParam, selectionKey: string | null) => {
    return useQuery({
        queryKey: [GET_PENDING_META_PAGES_QUERY_KEY, organizerId, selectionKey],
        queryFn: async () => (await socialClient.pendingPages(organizerId, selectionKey!)).data,
        enabled: !!organizerId && !!selectionKey,
        retry: false,
        staleTime: Infinity,
    });
};

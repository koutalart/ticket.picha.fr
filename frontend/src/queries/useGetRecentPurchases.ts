import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {recentPurchasesClientPublic} from "../api/recent-purchases.client.ts";

export const GET_RECENT_PURCHASES_QUERY_KEY = 'getRecentPurchases';

export const useGetRecentPurchases = (eventId: IdParam | undefined, enabled = true) => {
    return useQuery({
        queryKey: [GET_RECENT_PURCHASES_QUERY_KEY, eventId],
        queryFn: async () => {
            const {data} = await recentPurchasesClientPublic.get(eventId!);
            return data.purchases;
        },
        enabled: !!eventId && enabled,
        staleTime: 60 * 1000,
        refetchOnWindowFocus: false,
        retry: false,
    });
};

import {useQuery} from '@tanstack/react-query';
import {publicCheckInClient} from "../api/check-in.client";
import {IdParam} from "../types.ts";

export const GET_CHECK_IN_LIST_STATS_PUBLIC_QUERY_KEY = 'getCheckInListStatsPublic';

const POLL_INTERVAL_MS = 10000;

/**
 * Door counters, polled so that several scanners on the same list stay in sync.
 */
export const useGetCheckInListStatsPublic = (checkInListShortId: IdParam, enabled: boolean = true) => {
    return useQuery({
        queryKey: [GET_CHECK_IN_LIST_STATS_PUBLIC_QUERY_KEY, checkInListShortId],
        queryFn: async () => {
            const {data} = await publicCheckInClient.getCheckInListStats(checkInListShortId);
            return data;
        },
        enabled: enabled && !!checkInListShortId,
        refetchInterval: POLL_INTERVAL_MS,
        refetchIntervalInBackground: false,
        refetchOnWindowFocus: true,
        retry: false,
    });
};

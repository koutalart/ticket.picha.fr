import {useQuery} from "@tanstack/react-query";
import {publicApi} from "../api/public-client.ts";

export const GET_SERVER_CLOCK_OFFSET_QUERY_KEY = 'getServerClockOffset';

const measureOffset = async (): Promise<number> => {
    const sentAt = Date.now();
    const {data} = await publicApi.get<{ server_time: string }>('server-time');
    const receivedAt = Date.now();
    const serverTime = Date.parse(data.server_time);

    if (!Number.isFinite(serverTime)) {
        return 0;
    }

    return serverTime - (sentAt + receivedAt) / 2;
};

/**
 * Difference in milliseconds between the server clock and this device's clock.
 */
export const useGetServerClockOffset = (enabled = true) => {
    const query = useQuery({
        queryKey: [GET_SERVER_CLOCK_OFFSET_QUERY_KEY],
        queryFn: measureOffset,
        enabled,
        staleTime: 5 * 60 * 1000,
        refetchOnWindowFocus: true,
        retry: 2,
    });

    return {offset: query.data ?? 0, isSettled: query.isSuccess || query.isError};
};

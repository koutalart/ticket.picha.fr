import {keepPreviousData, useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {qrLinkClient} from "../api/qr-link.client.ts";

export const GET_QR_LINK_STATS_QUERY_KEY = 'getQrLinkStats';

export const useGetQrLinkStats = (organizerId: IdParam, qrLinkId: IdParam | undefined, days: number | null) => {
    return useQuery({
        queryKey: [GET_QR_LINK_STATS_QUERY_KEY, organizerId, qrLinkId, days],
        queryFn: async () => {
            const {data} = await qrLinkClient.stats(organizerId, qrLinkId!, days);
            return data;
        },
        enabled: !!organizerId && !!qrLinkId,
        placeholderData: keepPreviousData,
    });
};

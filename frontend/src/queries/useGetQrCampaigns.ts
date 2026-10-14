import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {qrLinkClient} from "../api/qr-link.client.ts";

export const GET_QR_CAMPAIGNS_QUERY_KEY = 'getQrCampaigns';

export const useGetQrCampaigns = (organizerId: IdParam) => {
    return useQuery({
        queryKey: [GET_QR_CAMPAIGNS_QUERY_KEY, organizerId],
        queryFn: async () => {
            const {data} = await qrLinkClient.campaigns(organizerId);
            return data;
        },
        enabled: !!organizerId,
    });
};

import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {qrLinkClient} from "../api/qr-link.client.ts";

export const GET_QR_LINKS_QUERY_KEY = 'getQrLinks';

export const useGetQrLinks = (organizerId: IdParam) => {
    return useQuery({
        queryKey: [GET_QR_LINKS_QUERY_KEY, organizerId],
        queryFn: async () => {
            const {data} = await qrLinkClient.all(organizerId);
            return data;
        },
        enabled: !!organizerId,
    });
};

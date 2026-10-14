import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {qrLinkClient} from "../api/qr-link.client.ts";
import {GET_QR_CAMPAIGNS_QUERY_KEY} from "../queries/useGetQrCampaigns.ts";
import {GET_QR_LINKS_QUERY_KEY} from "../queries/useGetQrLinks.ts";

export const useDeleteQrCampaign = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId, campaignId}: { organizerId: IdParam, campaignId: IdParam }) =>
            qrLinkClient.deleteCampaign(organizerId, campaignId),

        onSuccess: (_, variables) => Promise.all([
            queryClient.invalidateQueries({queryKey: [GET_QR_CAMPAIGNS_QUERY_KEY, variables.organizerId]}),
            queryClient.invalidateQueries({queryKey: [GET_QR_LINKS_QUERY_KEY, variables.organizerId]}),
        ]),
    });
};
